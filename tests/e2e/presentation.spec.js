import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// Presentation-only fixtures: real PHP templates and browser modules, no database
// writes. These complement, rather than replace, the real-server journeys.
const viewer = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const peer = { id: 2, username: 'Rowan' };
const rooms = ['The living room', 'Music & discoveries', 'Weekend plans'].map((name, index) => ({
  id: index + 1, name, key: `room-${index}`, visibility: 'public', member_role: 'member',
  minimum_age: 0, inactivity_timeout_seconds: 0, info_line: 'A place for everyday conversations.',
}));
const messages = [
  ['Rowan', 'Anyone else taking the slow route through Sunday?'],
  ['Jamie', 'Coffee, a good playlist, and absolutely no plans.'],
  ['Alex', 'That sounds like the right kind of afternoon.'],
  ['Rowan', 'Found a tiny record shop yesterday. Came home with more jazz than I intended.'],
  ['Jamie', 'A very reasonable souvenir. What are we listening to?'],
].map(([username, body], index) => ({
  id: index + 1, room_id: 1, user_id: username === 'Jamie' ? 1 : 2,
  username, body, type: 'text', deleted: false, mentions: [],
  created_at: `2026-10-04T10:0${index}:00Z`,
  reactions: index === 1 ? [{ emoji: '❤️', users: [peer] }] : [],
}));

async function fixture(page, signedIn = true) {
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1/', '');
    if (path === 'events/stream.php') {
      await route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
      return;
    }
    const responses = {
      'session.php': { user: signedIn ? viewer : null, csrf_token: 'presentation-fixture', registration_enabled: true },
      'registration-challenge.php': { challenge: null },
      'rooms/list.php': { rooms },
      'rooms/messages.php': { messages },
      'rooms/message-mutations.php': { messages: messages.map((message) => ({ ...message, can_edit: message.user_id === 1, can_delete: message.user_id === 1 })) },
      'attachments/metadata.php': { attachments: [] },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [viewer, peer, { username: 'Alex' }].map((user) => ({ ...user, idle_seconds: 0 })) },
      'rooms/mentionable-users.php': { users: [peer] },
      'rooms/pings.php': { pings: [] },
      'users/profile.php': { avatars_available: true, profile: { id: viewer.id, username: viewer.username, member_since: '2026-07-01T10:00:00Z', badge: null, has_avatar: false, avatar_version: null, can_remove_avatar: false } },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'account/mfa/status.php': { mfa: { enabled: false, available: false, credentials: [] } },
      'direct-messages/conversations.php': { conversations: [{ user: peer, unread_count: 0, last_message: { body: 'See you in the living room!', outgoing: false } }] },
      'direct-messages/block-status.php': { relationship: { blocked_by_me: false, messaging_available: true } },
      'direct-messages/history.php': { messages: messages.slice(0, 3).map((message) => ({ ...message, sender: { username: message.username }, outgoing: message.username === 'Jamie' })) },
      'direct-messages/read.php': {},
      'direct-messages/users.php': { users: [peer] },
      'direct-messages/attachments/metadata.php': { attachments: [] },
      'direct-messages/message-mutations.php': { messages: [] },
    };
    // Nobody in these fixtures has a profile picture, so initials are shown.
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (!(path in responses)) throw new Error(`Missing presentation fixture: ${path}`);
    await route.fulfill({ json: responses[path] });
  });
}

async function noOverflow(page) {
  // Allow the browser to finish its viewport/media-query layout after resizing.
  await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  const overflow = await page.evaluate(() => ({
    width: window.innerWidth,
    document: document.documentElement.scrollWidth,
    elements: [...document.querySelectorAll('body *')].filter((element) => element.getBoundingClientRect().right > window.innerWidth + 1).map((element) => `${element.tagName}.${element.className}`).slice(0, 10),
  }));
  expect(overflow.document, JSON.stringify(overflow)).toBeLessThanOrEqual(overflow.width);
}

async function accessible(page) {
  const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  expect(result.violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) }))).toEqual([]);
}

for (const theme of ['dark', 'light']) {
  test(`${theme}: welcoming auth and responsive conversation surfaces`, async ({ page }, testInfo) => {
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await page.emulateMedia({ colorScheme: theme, reducedMotion: 'reduce' });
    await page.setViewportSize({ width: 1440, height: 1000 });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await fixture(page, false);
    await page.goto('/');
    await expect(page.locator('#auth-shell')).toBeVisible();
    await accessible(page);
    await page.screenshot({ path: testInfo.outputPath(`auth-${theme}.png`), fullPage: true });
    await page.getByRole('tab', { name: 'Sign in' }).focus();
    await page.keyboard.press('ArrowRight');
    await expect(page.locator('#register-form')).toBeVisible();
    await page.setViewportSize({ width: 320, height: 740 });
    await noOverflow(page);
    await accessible(page);

    await page.unroute('**/api/v1/**');
    await fixture(page);
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto('/');
    await expect(page.locator('.message')).toHaveCount(messages.length);
    await expect(page.locator('.message-mutation-button').first()).toBeVisible();
    await accessible(page);
    await page.screenshot({ path: testInfo.outputPath(`chat-${theme}.png`) });
    for (const width of [640, 390, 320]) {
      await page.setViewportSize({ width, height: 844 });
      await noOverflow(page);
      await expect(page.locator('#send-button')).toBeInViewport();
      await expect(page.locator('#composer-input')).toBeInViewport();
    }
    await accessible(page);
    await page.locator('#drawer-toggle').click();
    await expect(page.getByRole('navigation', { name: 'Rooms' })).toBeVisible();
    await page.keyboard.press('Escape');
    await page.locator('#user-menu-button').click();
    await expect(page.getByRole('link', { name: 'Account', exact: true })).toBeVisible();
    await noOverflow(page);
    await page.keyboard.press('Escape');
    await page.locator('#composer-input').fill('A little hello.');
    await page.locator('#emoji-button').click();
    await expect(page.locator('.emoji-picker')).toBeVisible();
    await noOverflow(page);
    await page.locator('#emoji-button').click();
    await page.locator('.message-reply-button').last().click();
    await expect(page.locator('#reply-banner')).toBeVisible();
    await expect(page.locator('#send-button')).toBeInViewport();
    await noOverflow(page);
    await page.screenshot({ path: testInfo.outputPath(`chat-mobile-${theme}.png`) });

    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto('/messages.php');
    await expect(page.locator('.dm-message')).toHaveCount(3);
    await expect(page.locator('#dm-composer')).toBeVisible();
    await accessible(page);
    await page.screenshot({ path: testInfo.outputPath(`messages-${theme}.png`), fullPage: true });
    await page.setViewportSize({ width: 320, height: 740 });
    await noOverflow(page);
    await accessible(page);

    await page.locator('.dm-message .message-reply-button').last().click();
    await expect(page.locator('#dm-reply-banner')).toBeVisible();
    await noOverflow(page);
    const [field, send] = await Promise.all([page.locator('#dm-message-input').boundingBox(), page.locator('#dm-send').boundingBox()]);
    expect(send.x).toBeGreaterThanOrEqual(field.x + field.width);
    expect(send.y).toBeLessThan(field.y + field.height);

    await page.goto('/account.php');
    await expect(page.locator('#account-shell')).toBeVisible();
    await noOverflow(page);
    await accessible(page);
    await page.screenshot({ path: testInfo.outputPath(`account-mobile-${theme}.png`), fullPage: true });
    expect(errors).toEqual([]);
  });
}

test('empty conversations, long names, and user accessibility preferences', async ({ page }) => {
  await fixture(page);
  await page.route('**/api/v1/rooms/messages.php?**', (route) => route.fulfill({ json: { messages: [] } }));
  await page.route('**/api/v1/direct-messages/history.php?**', (route) => route.fulfill({ json: { messages: [] } }));
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.goto('/');
  await expect(page.locator('#empty-state')).toHaveText('No messages yet.');
  for (const width of [1440, 640, 320]) {
    await page.setViewportSize({ width, height: 844 });
    await page.locator('.sidebar-header h1').evaluate((heading) => { heading.textContent = 'A'.repeat(64); });
    await page.locator('#room-title').evaluate((heading) => { heading.textContent = 'A very long room name for a lovely group of people'; });
    await noOverflow(page);
    await expect(page.locator('#send-button')).toBeInViewport();
    const [empty, composer] = await Promise.all([page.locator('#empty-state').boundingBox(), page.locator('#composer-wrap').boundingBox()]);
    expect(empty.y + empty.height).toBeLessThanOrEqual(composer.y + 1);
  }
  await page.goto('/messages.php');
  await expect(page.locator('#dm-empty-state')).toHaveText('No messages yet.');
  await expect(page.locator('#dm-composer')).toBeVisible();
  await noOverflow(page);
  const [empty, composer] = await Promise.all([page.locator('#dm-empty-state').boundingBox(), page.locator('#dm-composer').boundingBox()]);
  expect(empty.y + empty.height).toBeLessThanOrEqual(composer.y + 1);
  await page.emulateMedia({ forcedColors: 'active' });
  await page.locator('#dm-message-input').focus();
  expect(await page.locator('#dm-message-input').evaluate((element) => getComputedStyle(element).outlineStyle)).not.toBe('none');
  expect(await page.locator('#dm-send').evaluate((element) => parseFloat(getComputedStyle(element).transitionDuration))).toBeLessThan(0.01);
});
