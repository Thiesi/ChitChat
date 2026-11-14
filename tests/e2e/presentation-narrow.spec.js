import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// S7g (#173, CCM's final J-read): narrow phones and large text. A 360 phone at 200%
// is 180 CSS px; nothing may be lost there or at 320: no sideways scrolling, the
// message field keeps the row, every destination stays on the bar, the room keeps
// its name, and each screen passes axe. Presentation only: the real pages against
// intercepted APIs, with invented people.
test.use({ serviceWorkers: 'block' });
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

const NOW = new Date(2026, 10, 14, 12, 0, 0);
const at = (minutesAgo) => new Date(NOW.getTime() - minutesAgo * 60_000).toISOString();

const jamie = {
  id: 1, username: 'Jamie', roles: ['user'], session_version: 1, guest: false, username_change: null,
  rules: { accepted_version: 1, accepted_at: '2026-10-01T10:00:00Z', acceptance_required: false },
  recovery_key: { exists: true, created_at: '2026-11-01T10:00:00Z' },
};
const rowan = { id: 2, username: 'Rowan' };
const priya = { id: 3, username: 'Priya' };
const jonas = { id: 4, username: 'Jonas' };
const room = (id, name, extra = {}) => ({
  id, name, key: `room-${id}`, visibility: 'public', member_role: 'member', minimum_age: 0, inactivity_timeout_seconds: 0,
  info_line: '', unread_count: 0, guest_access: 'none', last_message_at: at(id * 7), ...extra,
});
const rooms = [
  room(1, 'The living room', { info_line: 'Everyday talk, all welcome.', unread_count: 0 }),
  room(4, 'Board games night', { info_line: 'Fridays at eight.', unread_count: 3 }),
  room(5, 'Garden', { unread_count: 1 }),
  room(6, 'Book club', { member_role: null, info_line: 'This month: The Left Hand of Darkness.' }),
  room(7, 'Quiet corner', { member_role: null }),
];
let id = 100;
const say = (sender, body, minutes, extra = {}) => ({
  id: id++, room_id: 1, sender_id: sender.id, user_id: sender.id, username: sender.username, body, type: 'text', deleted: false,
  mentions: [], reactions: [], reply_to: null, attachment: null, created_at: at(minutes), ...extra,
});
const history = [
  say(rowan, 'Morning, all. Is anyone up for a walk later?', 95),
  say(rowan, 'The weather looks good until about four.', 94),
  say(priya, 'Count me in. Two-ish?', 80, { reactions: [{ emoji: '👍', users: [rowan, jonas] }] }),
  say(jamie, 'Two works for me too. I’ll bring a flask of tea.', 62),
  say(jonas, 'I can only make it for an hour, but I’ll join you at the bridge.', 41),
  say(rowan, 'Perfect. Bridge at two, then. 🌤', 12),
];
const dms = [
  { id: 900, body: 'Did you get the book back from Priya?', created_at: at(300), sender: rowan, recipient: jamie, outgoing: false },
  { id: 901, body: 'Yes, last night. Want to borrow it next?', created_at: at(280), sender: jamie, recipient: rowan, outgoing: true },
  { id: 902, body: 'Please! I’ll pick it up on Friday.', created_at: at(275), sender: rowan, recipient: jamie, outgoing: false },
].map((message) => ({ ...message, mentions: [], reactions: [], reply_to: null, read_at: null }));
const notifications = [
  { id: 60, kind: 'mentioned', title: 'You were mentioned', message: 'Jonas mentioned you in “Board games night”.', details: [], link: '/?room_id=4&message_id=77', read: false, created_at: at(30) },
  { id: 59, kind: 'pinged', title: 'Rowan pinged you', message: 'Rowan pinged you in “Garden”.', details: [], link: '/?room_id=5&ping_id=3', read: false, created_at: at(90) },
  { id: 58, kind: 'direct_message_received', title: 'New direct message', message: 'Rowan sent you a direct message.', details: [], link: '/messages.php?with=2', read: true, created_at: at(275) },
];
const results = [
  { kind: 'room', message_id: 101, excerpt: 'The weather looks good until about four.', excerpt_matches: [{ start: 4, length: 7 }], sender: rowan, room: { id: 1, key: 'room-1', name: 'The living room' }, peer: null, outgoing: null, created_at: at(94), edited_at: null },
  { kind: 'direct', message_id: 900, excerpt: 'Is the weather holding for Friday?', excerpt_matches: [{ start: 7, length: 7 }], sender: rowan, room: null, peer: rowan, outgoing: false, created_at: at(2000), edited_at: null },
];

async function fixture(page, { user = jamie, emptyRoom = false } = {}) {
  await page.clock.setFixedTime(NOW);
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname.replace('/api/v1/', '');
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    const responses = {
      'session.php': {
        user, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [], guest_access: true,
        capabilities: { totp: true, signed_in_sessions: true },
      },
      'rooms/list.php': { rooms },
      'rooms/messages.php': { messages: emptyRoom || url.searchParams.get('room_id') !== '1' ? [] : structuredClone(history) },
      'rooms/message-mutations.php': { messages: history.map((m) => ({ ...m, edited_at: null, can_edit: m.sender_id === 1, can_delete: m.sender_id === 1, can_moderate: false })) },
      'attachments/metadata.php': { attachments: [] },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [{ id: 1, username: 'Jamie' }, { id: 2, username: 'Rowan' }, { id: 3, username: 'Priya' }] },
      'rooms/mentionable-users.php': { users: [] },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: 105 },
      'account/notifications/list.php': { notifications, unread_count: 2 },
      'direct-messages/conversations.php': { conversations: [
        { user: rowan, unread_count: 0, last_message: { body: dms[2].body, outgoing: false, created_at: dms[2].created_at } },
        { user: priya, unread_count: 1, last_message: { body: 'See you at two!', outgoing: false, created_at: at(70) } },
      ] },
      'direct-messages/block-status.php': { relationship: { blocked_by_me: false, messaging_available: true } },
      'direct-messages/history.php': { messages: dms },
      'direct-messages/read.php': {},
      'direct-messages/attachments/metadata.php': { attachments: [] },
      'direct-messages/message-mutations.php': { messages: [] },
      'search/messages.php': { results, has_more: false, next_offset: null },
      'users/profile.php': { profile: { id: 1, username: 'Jamie', member_since: '2025-03-14T09:00:00Z', has_avatar: false }, avatars_available: true },
      'account/mfa/status.php': { mfa: { enabled: false, available: true, credentials: [], recovery_codes_remaining: 0, usable_methods: [], totp: { enabled: false, available: true, created_at: null, last_used_at: null } } },
      'account/identities/list.php': { providers: [], identities: [], has_password: true },
      'account/sessions/list.php': { sessions: [], maximum_sessions: 50 },
      'push/preferences.php': { preferences: { mentioned_push_enabled: true, pinged_push_enabled: true, direct_message_push_enabled: false, quiet_hours: null }, devices: [] },
      'policies.php': { policies: { rules: { source: 'none', version: 0 }, privacy: { source: 'none' } } },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
}

const phone = () => true;

const SCREENS = [
  { name: 'sign-in', path: '/', user: null, ready: (page) => expect(page.locator('#auth-greeting')).toBeVisible() },
  // #256: the register form, with its date field, fits too.
  { name: 'register', path: '/?register=1', user: null, ready: (page) => expect(page.locator('#register-birth-date')).toBeVisible() },
  { name: 'rooms-list', path: '/', ready: (page) => expect(page.locator('#room-list .room-button').first()).toBeVisible() },
  {
    name: 'room-conversation', path: '/',
    ready: async (page) => {
      if (phone(page)) await page.locator('#room-list .room-button', { hasText: 'The living room' }).click();
      await expect(page.locator('#message-list article.message')).toHaveCount(6);
    },
  },
  {
    name: 'room-empty', path: '/', emptyRoom: true,
    ready: async (page) => {
      if (phone(page)) await page.locator('#room-list .room-button', { hasText: 'Garden' }).click();
      else await page.locator('#room-list .room-button', { hasText: 'Garden' }).click();
      await expect(page.locator('#empty-state')).toContainText('Say hello');
    },
  },
  {
    name: 'leave-sheet', path: '/',
    ready: async (page) => {
      if (phone(page)) await page.locator('#room-list .room-button', { hasText: 'The living room' }).click();
      await expect(page.locator('#message-list article.message')).toHaveCount(6);
      await page.locator('#members-toggle').click();
      await page.getByRole('button', { name: 'Leave room…' }).click();
      await expect(page.getByRole('dialog')).toBeVisible();
    },
  },
  {
    name: 'direct', path: '/messages.php?user=2',
    ready: async (page) => {
      if (phone(page)) await page.locator('#dm-conversation-list .conversation-button').first().click();
      await expect(page.locator('#dm-message-list article.dm-message')).toHaveCount(3);
    },
  },
  { name: 'activity', path: '/notifications.php', ready: (page) => expect(page.locator('#privacy-notifications-status')).toContainText('unread') },
  {
    name: 'search', path: '/search.php',
    ready: async (page) => {
      await page.locator('#message-search-query').fill('weather');
      await page.getByRole('button', { name: 'Search', exact: true }).click();
      await expect(page.locator('#message-search-results > li')).toHaveCount(2);
    },
  },
  { name: 'you', path: '/account.php#appearance', ready: (page) => expect(page.locator('#account-loading')).toBeHidden() },
  { name: 'not-found', path: '/no-such-page', ready: (page) => expect(page.locator('main.status-page')).toBeVisible() },
];


for (const width of [180, 320]) {
  for (const screen of SCREENS) {
    test(`${width} CSS px: ${screen.name} fits, and passes axe`, async ({ page }) => {
      await page.setViewportSize({ width, height: Math.round(width * 2.1) });
      await page.addInitScript(() => localStorage.setItem('chitchat-new-look-seen', '1'));
      await fixture(page, { user: screen.user === undefined ? jamie : screen.user, emptyRoom: screen.emptyRoom });
      await page.goto(screen.path);
      await screen.ready(page);
      await page.mouse.move(0, 0);
      await page.waitForTimeout(250);
      expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth), 'no sideways scrolling').toBeLessThanOrEqual(0);
      const result = await new AxeBuilder({ page }).withTags(WCAG).analyze();
      expect(result.violations, result.violations.map((violation) => `${violation.id} ${violation.nodes.map((node) => node.target).join(' ')}`).join('; ')).toEqual([]);
    });
  }

  test(`${width} CSS px: the bottom bar keeps every destination, the field keeps the row, the room keeps its name`, async ({ page }) => {
    await page.setViewportSize({ width, height: Math.round(width * 2.1) });
    await page.addInitScript(() => localStorage.setItem('chitchat-new-look-seen', '1'));
    await fixture(page);
    await page.goto('/');
    const nav = page.getByRole('navigation', { name: 'Main' });
    for (const name of ['Rooms', /^Direct/, /^Activity/, 'You']) {
      const link = nav.getByRole('link', { name });
      await expect(link).toBeVisible();
      await expect(link).toBeInViewport({ ratio: 1 });
    }
    await page.locator('#room-list .room-button', { hasText: 'The living room' }).click();
    await expect(page.locator('#message-list article.message')).toHaveCount(6);
    // B1: room to write, not a few letters: the text area's content box (inside its padding).
    const room = await page.locator('#composer-input').evaluate((node) => { const style = getComputedStyle(node); return node.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight); });
    expect(room, 'the message field keeps room to write').toBeGreaterThanOrEqual(100);
    const title = page.locator('#room-title');
    expect(await title.evaluate((node) => node.getBoundingClientRect().width)).toBeGreaterThan(width * 0.3);
    await expect(page.locator('.composer-help.member-only')).toBeHidden();
  });
}

// S7h (CCM J-read S1, S4): notices and results come first on small screens.
for (const [width, height] of [[360, 780], [640, 450], [180, 390]]) {
  test(`${width}×${height}: Activity's first notice starts in the first view; search brings its results up`, async ({ page }) => {
    await page.setViewportSize({ width, height });
    await fixture(page);
    await page.goto('/notifications.php');
    await expect(page.locator('#privacy-notifications-status')).toContainText('unread');
    const first = await page.locator('.activity-item').first().boundingBox();
    expect(first.y, 'the first notice starts within the first view').toBeLessThan(height);
    await expect(page.locator('details.activity-about summary')).toHaveText('About these notices');

    await page.goto('/search.php');
    await page.locator('#message-search-query').fill('weather');
    await page.locator('#message-search-query').press('Enter');
    await expect(page.locator('#message-search-results > li')).toHaveCount(2);
    await expect(page.locator('#message-search-status')).toBeInViewport();
  });
}

test('180 CSS px: the direct encryption line keeps its words and Learn more in view (R2)', async ({ page }) => {
  await page.setViewportSize({ width: 180, height: 390 });
  await fixture(page);
  await page.goto('/messages.php?user=2');
  await page.locator('#dm-conversation-list .conversation-button').first().click();
  const line = page.locator('#dm-privacy-notice summary');
  // A separator, so the short words and Learn more don't read as one phrase (#351).
  await expect(line.locator('.privacy-short')).toHaveText('Not end-to-end encrypted ·');
  // No resize grip over the end of the placeholder (#351).
  await expect(page.locator('#dm-message-input')).toHaveCSS('resize', 'none');
  await expect(line.locator('.privacy-more')).toBeInViewport({ ratio: 1 });
  expect(await line.evaluate((node) => node.scrollWidth <= node.clientWidth)).toBe(true);
  await expect(line).toHaveAccessibleName(/Direct messages aren’t end-to-end encrypted\./);
});

// CCM's S7h read (H1–H6): the desktop paths, and exactly one scope control at any width.
for (const width of [1280, 385, 384, 360]) {
  test(`${width} CSS px: exactly one "Search in" control is shown and exposed (H1)`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 });
    await fixture(page);
    await page.goto('/search.php');
    await page.locator('#message-search-query').waitFor();
    const chips = page.getByRole('group', { name: 'Search in' });
    const select = page.getByRole('combobox', { name: 'Search in' });
    expect((await chips.count()) + (await select.count()), 'one control in the accessibility tree').toBe(1);
    await expect(width <= 384 ? select : chips).toBeVisible();
  });
}

for (const [width, height] of [[1280, 900], [640, 450]]) {
  test(`${width} CSS px: read rows are regular, page eyebrows muted, Mark all as read sized and end-aligned (H2–H5)`, async ({ page }) => {
    await page.setViewportSize({ width, height });
    await fixture(page);
    await page.goto('/notifications.php');
    await page.locator('.activity-item').first().waitFor();
    const weights = await page.locator('.activity-item').evaluateAll((items) => items.map((item) => [item.classList.contains('privacy-notification-unread'), Number(getComputedStyle(item.querySelector('.activity-lead')).fontWeight)]));
    for (const [unread, weight] of weights) expect(weight, unread ? 'unread' : 'read').toBe(unread ? 800 : 400);
    const muted = await page.evaluate(() => getComputedStyle(document.querySelector('.account-muted')).color);
    const eyebrow = page.locator('#privacy-notifications-shell > .account-header .account-eyebrow');
    if (width >= 768) await expect(eyebrow).toHaveCSS('color', muted);
    else await expect(eyebrow).toBeHidden();
    const mark = page.getByRole('button', { name: 'Mark all as read' });
    await expect(mark).toHaveCSS('font-size', '16px');
    const [box, row] = await Promise.all([mark.boundingBox(), page.locator('.privacy-notifications-heading-row').boundingBox()]);
    expect(box.height).toBeGreaterThanOrEqual(24);
    expect(Math.abs(box.x + box.width - (row.x + row.width)), 'end-aligned on the heading row').toBeLessThanOrEqual(1);

    await page.goto('/search.php');
    await page.locator('#message-search-query').waitFor();
    if (width >= 768) await expect(page.locator('.message-search-eyebrow')).toHaveCSS('color', muted);
  });
}

test('360 CSS px: the day heading stays eyebrow-sized (H6)', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 780 });
  await fixture(page);
  await page.goto('/notifications.php');
  const size = await page.locator('.activity-day-heading').first().evaluate((node) => parseFloat(getComputedStyle(node).fontSize));
  expect(size).toBeLessThan(14);
});

// CCM's S8 read (A): at 180 CSS px (360 at 200%) the direct composer stacks exactly like the
// room's: the field spans the row, attach and emoji sit at the same places, and the
// placeholder keeps its whole width.
test('180 CSS px: the direct composer stacks like the room composer (S8 A)', async ({ page }) => {
  await page.setViewportSize({ width: 180, height: 390 });
  await fixture(page);
  const layout = (field) => page.locator(field).evaluate((node) => {
    const form = node.closest('form').getBoundingClientRect();
    const box = node.getBoundingClientRect();
    const emoji = node.closest('form').querySelector('.emoji-button').getBoundingClientRect();
    const attach = node.closest('form').querySelector('.attachment-button').getBoundingClientRect();
    return { span: Math.round(form.right - box.right + box.left - form.left), emoji: Math.round(emoji.left - form.left), attach: Math.round(attach.left - form.left) };
  });
  await page.goto('/');
  await page.locator('#room-list .room-button', { hasText: 'The living room' }).click();
  await expect(page.locator('#message-list article.message')).toHaveCount(6);
  const room = await layout('#composer-input');
  await page.goto('/messages.php?user=2');
  await page.locator('#dm-conversation-list .conversation-button').first().click();
  await expect(page.locator('#dm-message-list article.dm-message')).toHaveCount(3);
  const direct = await layout('#dm-message-input');
  // The field leaves only the composer's padding and border on either side.
  expect(direct.span).toBeLessThanOrEqual(16);
  expect(direct).toEqual(room);
});

// CCM's S8 read (B): one focus ring on the composer: its own border while the field has
// focus; the field draws no outline of its own.
for (const [path, field, open] of [['/', '#composer-input', '#room-list .room-button'], ['/messages.php?user=2', '#dm-message-input', '#dm-conversation-list .conversation-button']]) {
  test(`one focus ring on the ${field === '#composer-input' ? 'room' : 'direct'} composer (S8 B)`, async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 780 });
    await fixture(page);
    await page.goto(path);
    await page.locator(open).first().click();
    await page.locator(field).focus();
    await expect(page.locator(field)).toHaveCSS('outline-style', 'none');
    const ring = await page.locator(field).evaluate((node) => getComputedStyle(node.closest('form')).boxShadow);
    expect(ring).toMatch(/0px 0px 0px 1px/);
  });
}
