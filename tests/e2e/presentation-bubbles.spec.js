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
  // A link and a file in your own (outgoing) bubble: the axe checks below cover their contrast.
  roomMessage(5, viewer, 'And the Seafarers expansion: https://example.com/seafarers'),
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
  { message_id: 5, id: 50, name: 'seafarers-rules.pdf', mime_type: 'application/pdf', size_bytes: 300000, sha256: 'f', previewable: false },
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
      // 70: the rendition is missing, the original works. 80: the rendition works. 90: the file
      // is there but nothing displays (it doesn't decode); a gone file is #334's own spec.
      if (id === '70' && preview) return route.fulfill({ status: 404, json: { error: { code: 'attachment_preview_unavailable' } } });
      if (id === '90') return route.fulfill({ contentType: 'image/png', body: 'not really a png' });
      return route.fulfill({ contentType: 'image/png', body: PNG });
    }
    const responses = {
      'session.php': { user: viewer, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'rooms/list.php': { rooms },
      'rooms/messages.php': { messages: history },
      // Edited: one of yours (outgoing bubble) and one from someone else, so axe checks "edited" on both.
      'rooms/message-mutations.php': { messages: history.map((message) => ({
        ...message, edited_at: [3, 4].includes(message.id) ? '2026-10-04T10:30:00Z' : null,
        can_edit: message.sender_id === 1, can_delete: message.sender_id === 1, can_moderate: false,
      })) },
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
      'direct-messages/message-mutations.php': { messages: [{
        id: 4, body: history[3].body, edited_at: '2026-10-04T10:30:00Z', deleted: false, can_edit: true, can_delete: true, mentions: [],
      }] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
}

const message = (page, id) => page.locator(`#message-list article[data-message-id="${id}"]`);

async function openRoom(page) {
  await page.goto('/');
  // Phones open on the Rooms list with nothing opened behind it (S2c): open the room as a person would.
  if ((page.viewportSize()?.width ?? 1280) < 768) await page.locator('#room-list .room-button').first().click();
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

// Lantern Next L4 (#388, owner decision): bubbles hold words. A run shows its time once;
// every message keeps its exact time in the page as <time datetime>, in its own text,
// and draws it again under the pointer and when the arrow keys reach it.
const drawn = (locator) => locator.evaluate((node) => { const box = node.getBoundingClientRect(); return box.width > 2 && box.height > 2; });

test('a run shows its time once; every message keeps its <time>, and shows it on hover and on keyboard focus', async ({ page }) => {
  await fixture(page);
  await openRoom(page);
  await page.mouse.move(0, 0);

  // In the page for every message, follow-on messages included: a real <time datetime> with
  // the short time as its text and the full moment as its title.
  for (const id of [1, 2, 3, 4, 5]) {
    const time = message(page, id).locator('.message-bubble > time.message-time');
    await expect(time).toHaveCount(1);
    await expect(time).toHaveAttribute('datetime', /^2026-10-04T08:0\d:00/);
    await expect(time).toHaveText(/\d{1,2}:\d{2}/);
    await expect(time).toHaveAttribute('title', /2026|26/);
    // Not display: none or visibility: hidden, so it stays in the accessibility tree.
    expect(await time.evaluate((node) => { const style = getComputedStyle(node); return style.display !== 'none' && style.visibility !== 'hidden'; })).toBe(true);
  }

  // Someone else's run: the time beside the name that starts it, as a copy for the eye only.
  const runTime = message(page, 1).locator('.message-header .message-run-time');
  await expect(runTime).toBeVisible();
  await expect(runTime).toHaveAttribute('aria-hidden', 'true');
  await expect(runTime).toHaveText(await message(page, 1).locator('time.message-time').innerText());
  await expect(message(page, 2).locator('.message-run-time')).toBeHidden();
  await expect(message(page, 4).locator('.message-run-time')).toBeHidden();
  // No time is drawn in their bubbles at rest.
  expect(await drawn(message(page, 1).locator('time.message-time'))).toBe(false);
  expect(await drawn(message(page, 2).locator('time.message-time'))).toBe(false);

  // Your own run: no name line, so its last bubble's time is drawn under it; the first isn't.
  expect(await drawn(message(page, 4).locator('time.message-time'))).toBe(false);
  expect(await drawn(message(page, 5).locator('time.message-time'))).toBe(true);
  const under = await message(page, 5).evaluate((node) => {
    const bubble = node.querySelector('.message-bubble').getBoundingClientRect();
    const time = node.querySelector('time.message-time').getBoundingClientRect();
    return { below: time.top >= bubble.bottom - 1, inside: time.right <= bubble.right + 1 && time.left >= bubble.left - 1 };
  });
  expect(under).toEqual({ below: true, inside: true });

  // Under the pointer the message's own time is drawn, on the bubble, without moving anything.
  // (Hovering may scroll the list, so positions are compared within the conversation.)
  const layout = () => page.evaluate(() => {
    const top = (id) => document.querySelector(`#message-list article[data-message-id="${id}"]`).getBoundingClientRect();
    return { gap: Math.round(top(3).top - top(2).bottom), height: Math.round(top(2).height), width: Math.round(top(2).width), scroll: document.querySelector('#message-list').scrollHeight };
  });
  const before = await layout();
  await message(page, 2).locator('.message-bubble').hover();
  expect(await drawn(message(page, 2).locator('time.message-time'))).toBe(true);
  expect(await layout()).toEqual(before);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
  await page.mouse.move(0, 0);
  expect(await drawn(message(page, 2).locator('time.message-time'))).toBe(false);

  // The conversation is one Tab stop; the arrow keys move through it. The message they
  // reach draws its time, and the one they leave stops.
  await page.locator('#message-list article[tabindex="0"]').focus();
  const current = async () => page.evaluate(() => document.activeElement?.closest('article')?.dataset.messageId ?? null);
  for (let step = 0; step < 12 && (await current()) !== '2'; step += 1) await page.keyboard.press('ArrowUp');
  expect(await current()).toBe('2');
  expect(await drawn(message(page, 2).locator('time.message-time'))).toBe(true);
  await page.keyboard.press('ArrowUp');
  expect(await current()).toBe('1');
  expect(await drawn(message(page, 1).locator('time.message-time'))).toBe(true);
  expect(await drawn(message(page, 2).locator('time.message-time'))).toBe(false);

  const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  expect(result.violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) })), 'a focused message with its time chip').toEqual([]);
});

// Lantern Next L4 (#388; review of #392): the name line's actions take no clicks until the
// pointer or focus is on the message. So a tap on the bubble, and the keyboard, must each
// bring Reply within reach, down to 180 CSS px, in every engine.
for (const width of [320, 180]) {
  test(`a tap on a bubble brings Reply within reach, and Reply opens the reply banner (${width}px)`, async ({ browser }) => {
    const context = await browser.newContext({ hasTouch: true, viewport: { width, height: 720 } });
    const page = await context.newPage();
    await fixture(page);
    await openRoom(page);
    const first = page.locator('#message-list article.message:is([data-run="start"], [data-run="single"]):not(.mine)').first();
    // Touch doesn't depend on hover or on a tap moving focus: a tap on the bubble opens the
    // message's actions as a sheet (transcript-keys.js), Reply among them.
    await first.locator('.message-bubble').first().tap();
    const sheet = page.locator('.message-menu-sheet');
    await expect(sheet).toBeVisible();
    const reply = sheet.getByRole('menuitem', { name: 'Reply' });
    await expect(reply).toBeVisible();
    expect(await reply.evaluate((node) => { const box = node.getBoundingClientRect(); const at = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2); return box.left >= 0 && box.right <= innerWidth + 0.5 && box.bottom <= innerHeight + 0.5 && box.height >= 44 && Boolean(at) && node.contains(at); })).toBe(true);
    await reply.tap();
    await expect(page.locator('#reply-banner')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await context.close();
  });

  test(`the arrow keys, Tab and Enter reach Reply on a run’s first message (${width}px)`, async ({ page }) => {
    await fixture(page);
    await page.setViewportSize({ width, height: 720 });
    await openRoom(page);
    const first = page.locator('#message-list article.message:is([data-run="start"], [data-run="single"]):not(.mine)').first();
    const id = await first.getAttribute('data-message-id');
    await page.locator('#composer-input').focus();
    for (let step = 0; step < 4; step += 1) {
      await page.keyboard.press('Shift+Tab');
      if (await page.evaluate(() => Boolean(document.activeElement?.closest('#message-list')))) break;
    }
    for (let step = 0; step < 12; step += 1) {
      if (await page.evaluate(() => document.activeElement?.dataset.messageId) === id) break;
      await page.keyboard.press('ArrowUp');
    }
    await expect(first).toBeFocused();
    const reply = first.locator('.message-header .message-reply-button');
    for (let step = 0; step < 8; step += 1) {
      await page.keyboard.press('Tab');
      if (await reply.evaluate((node) => node === document.activeElement)) break;
    }
    await expect(reply).toBeFocused();
    await expect(reply).toBeVisible();
    await expect(reply).toHaveCSS('opacity', '1');
    await page.keyboard.press('Enter');
    await expect(page.locator('#reply-banner')).toBeVisible();
  });
}

// The floating toolbar sits above its bubble; on the conversation's topmost message it must
// still lie inside the scrolling list, or its top would be cut off.
for (const width of [1280, 320]) {
  test(`the topmost direct message’s toolbar is not cut off by the list (${width}px)`, async ({ page }) => {
    await fixture(page);
    await page.setViewportSize({ width, height: 720 });
    await page.goto('/messages.php?user=2');
    const list = page.locator('#dm-message-list');
    // Phones open on the list of conversations: open this one as a person would.
    if (width < 768) await page.locator('#dm-conversation-list .conversation-button').first().click();
    await expect(list.locator('article.dm-message .message-bubble')).toHaveCount(5);
    await list.evaluate((node) => { node.scrollTop = 0; });
    const top = list.locator('article.dm-message').first();
    await top.focus();
    await page.waitForFunction(() => document.getAnimations().every((animation) => animation.playState !== 'running'));
    const toolbar = top.locator('.message-header');
    await expect(toolbar).toBeVisible();
    const boxes = await top.evaluate((node) => {
      const bar = node.querySelector('.message-header').getBoundingClientRect();
      let scroller = node.parentElement;
      while (scroller && !/auto|scroll|hidden|clip/.test(getComputedStyle(scroller).overflowY)) scroller = scroller.parentElement;
      const clip = scroller.getBoundingClientRect();
      const at = document.elementFromPoint(bar.left + bar.width / 2, bar.top + 1);
      return { above: clip.top - bar.top, opacity: getComputedStyle(node.querySelector('.message-header')).opacity, hit: Boolean(at) && node.querySelector('.message-header').contains(at) };
    });
    expect(boxes.opacity).toBe('1');
    expect(boxes.above).toBeLessThanOrEqual(0);
    expect(boxes.hit).toBe(true);
  });
}

test('direct messages keep a <time> too: under each run’s last bubble, and on hover for the rest', async ({ page }) => {
  await fixture(page);
  await page.goto('/messages.php?user=2');
  const list = page.locator('#dm-message-list');
  await expect(list.locator('article.dm-message .message-bubble')).toHaveCount(5);
  await page.mouse.move(0, 0);
  const dm = (id) => list.locator(`article[data-message-id="${id}"]`);
  for (const id of [1, 2, 3, 4, 5]) {
    const time = dm(id).locator('.dm-message-meta > time.dm-message-time');
    await expect(time).toHaveCount(1);
    await expect(time).toHaveAttribute('datetime', /^2026-10-0\dT/);
    await expect(time).toHaveText(/\d{1,2}:\d{2}/);
  }
  const ends = await list.locator('article.dm-message').evaluateAll((nodes) => nodes.map((node) => {
    const box = node.querySelector('.dm-message-meta').getBoundingClientRect();
    return { run: node.dataset.run, drawn: box.width > 2 && box.height > 2 };
  }));
  // Drawn at rest exactly where a run ends.
  for (const { run, drawn: shown } of ends) expect(shown, run).toBe(run === 'end' || run === 'single');
  const middle = list.locator('article.dm-message:not([data-run="end"]):not([data-run="single"])').first();
  await middle.locator('.message-bubble').hover();
  expect(await drawn(middle.locator('.dm-message-meta'))).toBe(true);
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
    await expect(message(page, 4).locator('.message-edited-indicator')).toBeVisible();
    await expect(message(page, 3).locator('.message-edited-indicator')).toBeVisible();
    // The header with "edited" shows on hover or focus; focus your own edited message so axe sees it.
    await message(page, 4).focus();
    // The actions fade in over 120 ms (transcript.css); axe run mid-fade blends their
    // half-transparent labels into the bubble and reports contrast people never get
    // to read (seen on the slower WebKit runner). They fade together; measure once shown.
    await expect(message(page, 4).locator('.message-reply-button')).toHaveCSS('opacity', '1');
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    const result = await new AxeBuilder({ page }).include('#message-list').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations).toEqual([]);

    // Direct messages: an edited outgoing message ("· edited" in its meta).
    await page.goto('/messages.php?user=2');
    // Phones open on the conversation list (S2c).
    await expect(page.locator('#dm-conversation-list .conversation-button').first()).toBeAttached();
    if ((page.viewportSize()?.width ?? 1280) < 768) await page.locator('#dm-conversation-list .conversation-button').first().click();
    await expect(page.locator('#dm-message-list article[data-message-id="4"] .message-edited-indicator')).toBeVisible();
    // The pointer that opened the conversation lies where a message now is, and that message's
    // toolbar fades in under it (seen in WebKit: Reply measured mid-fade). Nobody reads it
    // half-shown: move the pointer off and wait until every toolbar has settled, shown or not.
    await page.mouse.move(0, 0);
    await expect.poll(() => page.locator('#dm-message-list .message-header').evaluateAll((nodes) => nodes.every((node) => ['0', '1'].includes(getComputedStyle(node).opacity)))).toBe(true);
    const dms = await new AxeBuilder({ page }).include('#dm-message-list').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(dms.violations).toEqual([]);
  });
}

test('right-to-left: the transcript renders without overflow', async ({ page }) => {
  await page.addInitScript(() => document.addEventListener('DOMContentLoaded', () => document.documentElement.setAttribute('dir', 'rtl')));
  await fixture(page);
  await openRoom(page);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => window.innerWidth));
  await expect(message(page, 3).locator('.message-bubble')).toBeVisible();
});
