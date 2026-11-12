import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// A required new username on the web (#164; docs/api/username-change.md):
// writing pauses with a notice by the composer (rules first), user_renamed
// rewrites names on Rooms and Direct, and staff require one from the profile
// card or a person case. Presentation only: real pages against intercepted APIs.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const REASON = 'Your username was reported and found to break the community rules.';
const member = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1, username_change: null };
const gated = { ...member, username: 'member-k7q2xm', username_change: { required: true, required_at: '2026-11-12T10:00:00+00:00', reason: 'Slur in the name.', sign_in_with_previous_username: true } };
const moderator = { id: 3, username: 'Morgan', roles: ['global_moderator'], session_version: 1, username_change: null };
const peer = { id: 2, username: 'Rowan' };
const rooms = [{
  id: 1, name: 'The living room', key: 'room-0', visibility: 'public', member_role: 'member',
  minimum_age: 0, inactivity_timeout_seconds: 0, info_line: 'Everyday talk.', unread_count: 0, guest_access: 'read_write',
}];
const messages = [
  { id: 1, room_id: 1, sender_id: 2, user_id: 2, username: 'Rowan', body: 'Anyone around?', type: 'text', deleted: false, mentions: [], reactions: [], reply_to: null, attachment: null, created_at: '2026-11-12T10:00:00Z' },
  { id: 2, room_id: 1, sender_id: 1, user_id: 1, username: 'Jamie', body: 'Hi @Rowan', type: 'text', deleted: false, mentions: [{ user_id: 2, username: 'Rowan' }], reactions: [], reply_to: null, attachment: null, created_at: '2026-11-12T10:01:00Z' },
];
const everywhere = (extra = {}) => ({ can_mute: false, mute: null, can_ban: false, ban: null, can_kick: false, can_require_username_change: true, username_change_required: false, ...extra });
const peerProfile = (moderation = null) => ({
  id: 2, username: 'Rowan', member_since: '2026-07-01T10:00:00Z', badge: null, has_avatar: false,
  avatar_version: null, can_remove_avatar: false, ignored: false, can_report: true, moderation,
});
const personCase = {
  id: 42, subject_kind: 'user', status: 'open', report_count: 1, subject: { id: 2, username: 'Rowan' },
  room: { id: 1, name: 'The living room' }, first_reported_at: '2026-11-12T09:00:00Z', last_reported_at: '2026-11-12T09:00:00Z', assigned_to: null,
};

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

async function fixture(page, { session = () => member, profile = peerProfile(), overrides = {} } = {}) {
  const requests = [];
  await stubEventSource(page);
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace('/api/v1/', '');
    if (request.method() === 'POST') requests.push({ path, body: request.postDataJSON?.() ?? null });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path in overrides) {
      const value = typeof overrides[path] === 'function' ? overrides[path](request) : overrides[path];
      if (Number.isInteger(value?.status)) return route.fulfill({ status: value.status, json: value.json });
      return route.fulfill({ json: value });
    }
    const responses = {
      'session.php': { user: session(), csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [], capabilities: { moderated_username_change: true } },
      'rooms/list.php': { rooms },
      'rooms/messages.php': { messages: structuredClone(messages) },
      'rooms/message-mutations.php': { messages: [] },
      'attachments/metadata.php': { attachments: [] },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [] },
      'rooms/mentionable-users.php': { users: [peer] },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: 2 },
      'users/profile.php': { avatars_available: true, profile },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [{ user: peer, unread_count: 0, last_message: { body: 'See you', outgoing: false } }] },
      'direct-messages/block-status.php': { relationship: { blocked_by_me: false, messaging_available: true } },
      'direct-messages/history.php': { messages: [
        { id: 7, body: 'See you', created_at: '2026-11-12T10:00:00Z', mentions: [], reactions: [], reply_to: null, sender: peer, recipient: member, outgoing: false, read_at: null },
      ] },
      'direct-messages/read.php': {},
      'direct-messages/attachments/metadata.php': { attachments: [] },
      'direct-messages/message-mutations.php': { messages: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return requests;
}

async function openRoom(page) {
  await page.goto('/');
  await expect(page.locator('#message-list article.message')).toHaveCount(2);
}

async function axe(page, label) {
  await page.mouse.move(0, 0);
  const result = await new AxeBuilder({ page }).withTags(WCAG).analyze();
  expect(result.violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) })), label).toEqual([]);
}

const notice = (page) => page.locator('#composer-wrap [data-username-gate]');

test('while a new username is required, writing pauses with a notice and the moderator’s reason', async ({ page }) => {
  await fixture(page, { session: () => gated });
  await openRoom(page);
  await expect(notice(page)).toBeVisible();
  await expect(notice(page)).toContainText('Choose a new username to keep writing.');
  await expect(notice(page)).toContainText('The moderator’s reason: Slur in the name.');
  await expect(notice(page).getByRole('link', { name: 'Choose a name' })).toHaveAttribute('href', '/choose-username.php');
  await expect(page.locator('#composer-form')).toBeHidden();
  await expect(page.locator('#current-user')).toHaveText('member-k7q2xm');
  await axe(page, 'gated room');

  await page.goto('/messages.php');
  await page.locator('.conversation-button', { hasText: 'Rowan' }).click();
  await expect(page.locator('[data-username-gate]')).toBeVisible();
  await expect(page.locator('#dm-composer')).toBeHidden();
});

test('the rules come first: only their notice shows until agreed', async ({ page }) => {
  await fixture(page, { session: () => ({ ...gated, rules: { accepted_version: 1, accepted_at: '2026-10-01T12:00:00Z', acceptance_required: true } }) });
  await page.addInitScript(() => sessionStorage.setItem('chitchat-rules-dialog-shown', '1'));
  await openRoom(page);
  await expect(page.locator('#composer-wrap [data-rules-gate]')).toBeVisible();
  await expect(notice(page)).toBeHidden();
});

test('a write refused with username_change_required brings the notice', async ({ page }) => {
  let changed = false;
  await fixture(page, {
    session: () => (changed ? gated : member),
    overrides: { 'rooms/send.php': () => { changed = true; return { status: 403, json: { error: { code: 'username_change_required', message: 'Choose a new username to write. You can still read.' } } }; } },
  });
  await openRoom(page);
  await expect(notice(page)).toBeHidden();
  await page.locator('#composer-input').fill('Hello');
  await page.locator('#composer-input').press('Enter');
  await expect(notice(page)).toBeVisible();
  await expect(page.locator('#composer-form')).toBeHidden();
});

test('user_renamed rewrites the person’s name in authors and mentions; your own refreshes the session', async ({ page }) => {
  let self = gated;
  await fixture(page, { session: () => self });
  await openRoom(page);
  await page.evaluate(() => window.__emit('user_renamed', { user: { id: 2, username: 'member-q2w3e4' } }));
  await expect(page.locator('#message-list article[data-message-id="1"]')).toContainText('member-q2w3e4');
  await expect(page.locator('#message-list article[data-message-id="1"]')).not.toContainText('Rowan');

  // You chose a new name on another device: the notice goes and writing returns.
  self = { ...member, username: 'JamieB' };
  await page.evaluate(() => window.__emit('user_renamed', { user: { id: 1, username: 'JamieB' } }));
  await expect(page.locator('#current-user')).toHaveText('JamieB');
  await expect(notice(page)).toBeHidden();
  await expect(page.locator('#composer-form')).toBeVisible();
  await expect(page.locator('#message-list article[data-message-id="2"]')).toContainText('JamieB');
});

test('on Direct, user_renamed renames the open conversation and its header', async ({ page }) => {
  await fixture(page);
  await page.goto('/messages.php');
  await page.locator('.conversation-button', { hasText: 'Rowan' }).click();
  await expect(page.locator('#dm-peer-name')).toHaveText('Rowan');
  await page.evaluate(() => window.__emit('user_renamed', { user: { id: 2, username: 'member-q2w3e4' } }));
  await expect(page.locator('#dm-peer-name')).toHaveText('member-q2w3e4');
  await expect(page.locator('#dm-message-list')).toContainText('member-q2w3e4');
});

test('staff require a new username from the profile card, with a reason the person sees', async ({ page }) => {
  const requests = await fixture(page, {
    session: () => moderator,
    profile: peerProfile({ room: null, everywhere: everywhere() }),
    overrides: { 'moderation/require-username-change.php': { user: { id: 2, username: 'member-q2w3e4' }, case: null } },
  });
  await openRoom(page);
  await page.locator('.message .user-name', { hasText: 'Rowan' }).first().click();
  await page.locator('#name-menu').getByRole('button', { name: 'Require a new username…' }).click();
  const dialog = page.getByRole('dialog', { name: 'Require Rowan to choose a new username?' });
  await expect(dialog).toBeVisible();
  await expect(dialog).toContainText('replaced at once');
  const reason = dialog.getByRole('textbox', { name: /Reason/ });
  await expect(reason).toHaveValue(REASON);
  await expect(reason).toBeFocused();
  await axe(page, 'require dialog');
  // A reason is required.
  await reason.fill('   ');
  await dialog.getByRole('button', { name: 'Replace the name' }).click();
  await expect(dialog).toBeVisible();
  expect(requests.some((entry) => entry.path === 'moderation/require-username-change.php')).toBe(false);
  await reason.fill('Slur in the name.');
  await dialog.getByRole('button', { name: 'Replace the name' }).click();
  await expect.poll(() => requests.find((entry) => entry.path === 'moderation/require-username-change.php')?.body)
    .toEqual({ user_id: 2, reason: 'Slur in the name.', case_id: null });
});

test('the card says when a change is pending, and offers no second one', async ({ page }) => {
  await fixture(page, {
    session: () => moderator,
    profile: peerProfile({ room: null, everywhere: everywhere({ can_require_username_change: false, username_change_required: true }) }),
  });
  await openRoom(page);
  await page.locator('.message .user-name', { hasText: 'Rowan' }).first().click();
  await expect(page.locator('#name-menu')).toContainText('Has to choose a new username');
  await expect(page.locator('#name-menu').getByRole('button', { name: 'Require a new username…' })).toHaveCount(0);
});

test('in a person case, requiring a new username closes the case', async ({ page }) => {
  const requests = await fixture(page, {
    session: () => moderator,
    profile: peerProfile({ room: null, everywhere: everywhere() }),
    overrides: {
      'moderation/cases.php': { cases: [personCase], has_more: false, next_before_id: null },
      'moderation/case.php': { case: { ...personCase, snapshot: { username: 'Rowan', avatar_url: null }, avatar_changed_since_report: false, reports: [
        { reporter: { id: 1, username: 'Jamie' }, category: 'offensive_username', details: null, created_at: '2026-11-12T09:00:00Z' },
      ] } },
      'moderation/require-username-change.php': (request) => ({
        user: { id: 2, username: 'member-q2w3e4' },
        case: { ...personCase, status: 'resolved', resolution_code: 'username_change_required', resolved_by: moderator, subject: { id: 2, username: 'member-q2w3e4' }, reports: [] },
        echo: request.postDataJSON(),
      }),
    },
  });
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto('/moderation.php');
  await expect(page.locator('#moderation-resolution-code option[value="username_change_required"]')).not.toHaveAttribute('hidden', '');
  await page.getByRole('button', { name: 'Require a new username…' }).click();
  await page.getByRole('dialog', { name: /Require Rowan/ }).getByRole('button', { name: 'Replace the name' }).click();
  await expect(page.locator('#moderation-closed-summary')).toContainText('new username required');
  await expect.poll(() => requests.find((entry) => entry.path === 'moderation/require-username-change.php')?.body)
    .toEqual({ user_id: 2, reason: REASON, case_id: 42 });
});

// ---- The chooser (/choose-username.php) ----

async function chooser(page, { user = gated, reply = null } = {}) {
  const requests = await fixture(page, { session: () => user, overrides: reply ? { 'account/username.php': reply } : {} });
  await page.goto('/choose-username.php');
  return requests;
}

test('the chooser: the onboarding card, the reason, the name meanwhile, one field', async ({ page }) => {
  await chooser(page);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('ChitChat');
  await expect(page.getByRole('heading', { level: 2 })).toHaveText('Choose a new username');
  await expect(page.locator('#choose-username-reason')).toHaveText('The moderator’s reason: Slur in the name.');
  await expect(page.locator('#choose-username-current')).toContainText('member-k7q2xm');
  const field = page.getByRole('textbox', { name: /New username/ });
  await expect(field).toBeFocused();
  await expect(field).toHaveCSS('font-size', '16px');
  await expect(field).toHaveAttribute('autocomplete', 'username');
  await expect(page).toHaveTitle(/^Choose a new username · /);
  await axe(page, 'chooser');
});

test('the chooser explains each refusal inline, and a chosen name goes back to the conversation', async ({ page }) => {
  let attempts = 0;
  const requests = await chooser(page, { reply: () => {
    attempts += 1;
    if (attempts === 1) return { status: 409, json: { error: { code: 'username_taken', message: 'Taken.' } } };
    if (attempts === 2) return { status: 429, json: { error: { code: 'rate_limited', message: 'Slow down.', retry_after_seconds: 30 } } };
    return { user: { ...member, username: 'JamieB' } };
  } });
  const field = page.getByRole('textbox', { name: /New username/ });
  const error = page.locator('#choose-username-error');
  // Checked before sending: no request for a name the rules refuse.
  await field.fill('x');
  await page.getByRole('button', { name: 'Use this name' }).click();
  await expect(error).toHaveText('Use 3–32 letters, digits, dots, underscores or hyphens, starting with a letter or digit.');
  await expect(field).toHaveAttribute('aria-invalid', 'true');
  await expect(field).toHaveAccessibleDescription(/Use 3–32 letters/);
  expect(requests.some((entry) => entry.path === 'account/username.php')).toBe(false);
  await axe(page, 'chooser with an error');

  await field.fill('Rowan');
  await page.getByRole('button', { name: 'Use this name' }).click();
  await expect(error).toHaveText('That name isn’t available. Try another.');
  await field.fill('Rowan2');
  await expect(error).toBeHidden();
  await page.getByRole('button', { name: 'Use this name' }).click();
  await expect(error).toHaveText('Too many tries for now. Wait a little, then try again.');
  await field.fill('JamieB');
  await page.getByRole('button', { name: 'Use this name' }).click();
  await expect(page).toHaveURL(/\/$/);
  expect(requests.filter((entry) => entry.path === 'account/username.php').at(-1).body).toEqual({ username: 'JamieB' });
});

test('the chooser tells an account with nothing to change, and sends signed-out people to the start', async ({ page }) => {
  await chooser(page, { user: member });
  await expect(page.getByRole('heading', { level: 2 })).toHaveText('Your username is fine');
  await expect(page.locator('#choose-username-done-text')).toHaveText('Nothing to change: your username is Jamie.');
  await expect(page.locator('#choose-username-form')).toBeHidden();
  await page.unrouteAll({ behavior: 'ignoreErrors' });
  await chooser(page, { user: null });
  await expect(page).toHaveURL(/\/$/);
});

for (const width of [1280, 360]) {
  test(`the chooser in dark at ${width}px passes axe and fits`, async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('chitchat-theme', 'dark'));
    await page.setViewportSize({ width, height: 800 });
    await chooser(page);
    await expect(page.getByRole('textbox', { name: /New username/ })).toBeVisible();
    await axe(page, `${width} dark`);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  });
}
