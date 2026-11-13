import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// #300: handing a room on from the chat (the transfer API is #273/#277).
// The profile card offers "Make owner of #room…" only when the server says
// this person can own the room now (moderation.room.can_transfer_ownership);
// the styled dialog asks first, the transfer asks for a fresh sign-in check,
// and every refusal is a plain sentence. Presentation only: the real page
// against intercepted APIs.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const member = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1, username_change: null };
const peer = { id: 2, username: 'Rowan' };
const rooms = [{
  id: 1, name: 'The living room', key: 'room-0', visibility: 'public', member_role: 'owner',
  minimum_age: 0, inactivity_timeout_seconds: 0, info_line: 'Everyday talk.', unread_count: 0, guest_access: 'none',
}];
const messages = [
  { id: 1, room_id: 1, sender_id: 2, user_id: 2, username: 'Rowan', body: 'Anyone around?', type: 'text', deleted: false, mentions: [], reactions: [], reply_to: null, attachment: null, created_at: '2026-11-12T10:00:00Z' },
  { id: 2, room_id: 1, sender_id: 1, user_id: 1, username: 'Jamie', body: 'Hi Rowan', type: 'text', deleted: false, mentions: [], reactions: [], reply_to: null, attachment: null, created_at: '2026-11-12T10:01:00Z' },
];
const roomOptions = (extra = {}) => ({
  id: 1, name: 'The living room', member_role: 'member', can_set_moderator: true, can_remove: false, can_mute: false, mute: null,
  can_transfer_ownership: true, ...extra,
});
const peerProfile = (room) => ({
  id: 2, username: 'Rowan', member_since: '2026-07-01T10:00:00Z', badge: null, has_avatar: false,
  avatar_version: null, can_remove_avatar: false, ignored: false, can_report: true,
  moderation: { room, everywhere: null },
});
const refusal = (code, message = 'Refused.') => ({ status: code === 'step_up_required' || code === 'forbidden' ? 403 : 409, json: { error: { code, message } } });

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

async function fixture(page, { profile = peerProfile(roomOptions()), transfer = [{ room: { ...rooms[0], member_role: 'moderator' } }] } = {}) {
  const requests = [];
  const answers = [...transfer];
  await stubEventSource(page);
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace('/api/v1/', '');
    if (request.method() === 'POST') requests.push({ path, body: request.postDataJSON?.() ?? null });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path === 'rooms/transfer-ownership.php') {
      const answer = answers.length > 1 ? answers.shift() : answers[0];
      return Number.isInteger(answer?.status) ? route.fulfill({ status: answer.status, json: answer.json }) : route.fulfill({ json: answer });
    }
    const responses = {
      'session.php': { user: member, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
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
      'direct-messages/conversations.php': { conversations: [] },
      'account/mfa/status.php': { mfa: { enabled: false } },
      'account/identities/list.php': { providers: [], identities: [], has_password: true },
      'step-up.php': { verified: true },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return requests;
}

async function openCard(page) {
  await page.goto('/');
  await expect(page.locator('#message-list article.message')).toHaveCount(2);
  await page.locator('.message .user-name', { hasText: 'Rowan' }).first().click();
  await expect(page.locator('#name-menu')).toBeVisible();
}

const card = (page) => page.locator('#name-menu');
const handOver = (page) => card(page).getByRole('button', { name: 'Make owner of #The living room…' });
const dialog = (page) => page.getByRole('dialog', { name: 'Make Rowan the owner of #The living room?' });
const transfers = (requests) => requests.filter((entry) => entry.path === 'rooms/transfer-ownership.php');

async function axe(page, label) {
  await page.mouse.move(0, 0);
  const result = await new AxeBuilder({ page }).withTags(WCAG).analyze();
  expect(result.violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) })), label).toEqual([]);
}

test('only offered when the server says this person can own the room now', async ({ page }) => {
  await fixture(page, { profile: peerProfile(roomOptions({ can_transfer_ownership: false })) });
  await openCard(page);
  await expect(card(page).getByRole('button', { name: 'Make room moderator' })).toBeVisible();
  await expect(handOver(page)).toHaveCount(0);
});

test('an older server without the flag offers nothing', async ({ page }) => {
  const room = roomOptions();
  delete room.can_transfer_ownership;
  await fixture(page, { profile: peerProfile(room) });
  await openCard(page);
  await expect(card(page).getByRole('button', { name: 'Make room moderator' })).toBeVisible();
  await expect(handOver(page)).toHaveCount(0);
});

test('the dialog asks first; Escape hands nothing over', async ({ page }) => {
  const requests = await fixture(page);
  await openCard(page);
  await handOver(page).click();
  await expect(dialog(page)).toBeVisible();
  await expect(dialog(page)).toContainText('Rowan can then change the room’s details, members and roles. The current owner stays in the room as a moderator.');
  await expect(dialog(page).getByRole('button', { name: 'Cancel' })).toBeFocused();
  await expect(dialog(page).getByRole('button', { name: 'Hand over' })).toBeVisible();
  await axe(page, 'hand-over dialog');
  await page.keyboard.press('Escape');
  await expect(dialog(page)).toHaveCount(0);
  expect(transfers(requests)).toEqual([]);
});

test('Hand over asks for the password first, then transfers and says so', async ({ page }) => {
  const requests = await fixture(page, { transfer: [refusal('step_up_required', 'Confirm it’s you.'), { room: { ...rooms[0], member_role: 'moderator' } }] });
  await openCard(page);
  await handOver(page).click();
  await dialog(page).getByRole('button', { name: 'Hand over' }).click();
  const check = page.getByRole('dialog', { name: 'Confirm this sensitive action' });
  await expect(check).toBeVisible();
  await check.getByLabel('Current password').fill('correct horse battery staple');
  await check.getByRole('button', { name: 'Verify password' }).click();
  await expect(card(page)).toContainText('Rowan owns #The living room now.');
  expect(transfers(requests).map((entry) => entry.body)).toEqual([{ room_id: 1, target_user_id: 2 }, { room_id: 1, target_user_id: 2 }]);
});

test('cancelling the sign-in check says nothing and leaves the action ready', async ({ page }) => {
  await fixture(page, { transfer: [refusal('step_up_required', 'Confirm it’s you.')] });
  await openCard(page);
  await handOver(page).click();
  await dialog(page).getByRole('button', { name: 'Hand over' }).click();
  const check = page.getByRole('dialog', { name: 'Confirm this sensitive action' });
  await check.getByRole('button', { name: 'Cancel' }).click();
  await expect(check).toBeHidden();
  await expect(handOver(page)).toBeEnabled();
  await expect(card(page)).not.toContainText('cancelled');
});

for (const [code, sentence] of [
  ['transfer_target_not_eligible', 'Rowan can’t own this room right now (guests, closing or banned accounts can’t).'],
  ['ownership_changed', '#The living room changed meanwhile. Reload and try again.'],
  ['already_owner', 'Rowan already owns #The living room.'],
  ['membership_not_found', 'Rowan isn’t in #The living room any more.'],
  ['forbidden', 'You can’t hand #The living room on.'],
]) {
  test(`a refusal (${code}) is a plain sentence`, async ({ page }) => {
    await fixture(page, { transfer: [refusal(code)] });
    await openCard(page);
    await handOver(page).click();
    await dialog(page).getByRole('button', { name: 'Hand over' }).click();
    await expect(card(page)).toContainText(sentence);
    await expect(handOver(page)).toBeEnabled();
  });
}
