import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// S6a (#172, spec comment 6842902529): Administration in the Dusk frame.
// Every action keeps its endpoint and step-up; the native confirms are the
// shared styled dialog; staff can hand a room on or take it over (#300) when
// the server offers it. Presentation only: the real page against intercepted
// APIs, with fixed data, so the baseline is the same on every day.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const admin = { id: 1, username: 'Jamie', roles: ['admin'], session_version: 1 };
const rowan = { id: 2, username: 'Rowan', roles: [], created_at: '2026-03-01T10:00:00Z', last_login_at: '2026-10-03T18:00:00Z', active_ban: null };
const priya = { id: 3, username: 'Priya', roles: ['global_moderator'], created_at: '2026-04-11T10:00:00Z', last_login_at: null, active_ban: { expires_at: '2026-10-20T10:00:00Z' } };
const room = {
  id: 4, key: 'garden', name: 'Garden', info_line: 'Seeds, soil and patience.', visibility: 'public', minimum_age: 0,
  guest_access: 'none', inactivity_timeout_seconds: 0, member_role: null, unread_count: 0, can_take_ownership: true,
};
const members = () => [
  { id: 5, username: 'Morgan', role: 'owner', joined_at: '2026-02-01T10:00:00Z', active_connections: 1, can_transfer_ownership: false },
  { id: 2, username: 'Rowan', role: 'moderator', joined_at: '2026-03-02T10:00:00Z', active_connections: 0, can_transfer_ownership: true },
  { id: 3, username: 'Priya', role: 'member', joined_at: '2026-04-12T10:00:00Z', active_connections: 2, can_transfer_ownership: false },
];
const invitations = [{ id: 6, username: 'Sam', invited_by_username: 'Morgan', created_at: '2026-10-01T09:00:00Z' }];
const audit = Array.from({ length: 50 }, (_, index) => ({
  id: 900 - index, action: index === 0 ? 'room.ownership_transferred' : 'room.updated', created_at: '2026-10-04T11:00:00Z',
  actor_username: 'Jamie', subject_type: 'room', subject_id: 4, ip_address: '192.0.2.10', metadata: index === 0 ? { from_user_id: 5, to_user_id: 2 } : {},
}));
const refusal = (status, code) => ({ status, json: { error: { code, message: 'Refused.' } } });

async function fixture(page, { snapshotRoom = room, transfer = [{ room }], overrides = {} } = {}) {
  const requests = [];
  const answers = [...transfer];
  await page.clock.setFixedTime(new Date('2026-10-04T12:00:00Z'));
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname.replace('/api/v1/', '');
    if (request.method() === 'POST') requests.push({ path, body: request.postDataJSON?.() ?? null });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path === 'rooms/transfer-ownership.php') {
      const answer = answers.length > 1 ? answers.shift() : answers[0];
      return Number.isInteger(answer?.status) ? route.fulfill({ status: answer.status, json: answer.json }) : route.fulfill({ json: answer });
    }
    if (path in overrides) {
      const value = overrides[path];
      return Number.isInteger(value?.status) ? route.fulfill({ status: value.status, json: value.json }) : route.fulfill({ json: value });
    }
    const responses = {
      'session.php': { user: admin, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [], privacy: {} },
      'rooms/list.php': { rooms: [room] },
      'rooms/deleted.php': { rooms: [{ id: 9, name: 'Attic', deleted_at: '2026-10-01T10:00:00Z', purge_after: '2026-10-31T10:00:00Z' }] },
      'admin/rooms/snapshot.php': { room: snapshotRoom, members: members(), invitations },
      'admin/rooms/search-users.php': { users: [{ id: 7, username: 'Kit' }] },
      'admin/users.php': { users: [rowan, priya] },
      'admin/audit.php': { entries: url.searchParams.has('before_id') ? [] : audit },
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

async function open(page, width = 1280) {
  await page.setViewportSize({ width, height: 900 });
  await page.goto('/admin.php');
  await expect(page.locator('#admin-shell')).toBeVisible();
}

async function openRooms(page, width) {
  await open(page, width);
  await page.locator('#admin-tabs').getByRole('button', { name: 'Rooms' }).click();
  await expect(page.locator('#room-member-list')).toContainText('Rowan');
}

async function axe(page, label) {
  await page.mouse.move(0, 0);
  await page.waitForTimeout(250);
  const result = await new AxeBuilder({ page }).withTags(WCAG).analyze();
  expect(result.violations, `${label}: ${result.violations.map((violation) => `${violation.id} ${violation.nodes.map((node) => node.target).join(' ')}`).join('; ')}`).toEqual([]);
}

const posts = (requests, path) => requests.filter((entry) => entry.path === path);
const memberRow = (page, name) => page.locator('#room-member-list .admin-list-item', { hasText: name });

// ---- The frame ----

test('actions take the steward accent; destructive ones stay rose', async ({ page }) => {
  await fixture(page);
  await openRooms(page);
  const read = () => page.evaluate(() => {
    const probe = document.createElement('span');
    document.body.append(probe);
    probe.style.color = 'var(--steward)';
    const steward = getComputedStyle(probe).color;
    probe.remove();
    return {
      steward,
      primary: getComputedStyle(document.querySelector('#room-settings-form .primary-button')).backgroundColor,
      danger: getComputedStyle(document.querySelector('#room-delete')).borderColor,
      tab: getComputedStyle(document.querySelector('#admin-tabs [aria-pressed="true"]')).borderTopColor,
    };
  });
  // Buttons and tabs ease into their colours, so wait for them to settle.
  await expect.poll(async () => { const c = await read(); return c.primary === c.steward && c.tab === c.steward; }).toBe(true);
  const colours = await read();
  expect(colours.danger).not.toBe(colours.steward);
});

// ---- Users ----

test('Sign out everywhere, Ban and Reset password ask in the styled dialog; Cancel posts nothing', async ({ page }) => {
  const requests = await fixture(page);
  let native = 0;
  page.on('dialog', (dialog) => { native += 1; void dialog.dismiss(); });
  await open(page);
  await page.locator('#user-list .admin-list-item', { hasText: 'Rowan' }).getByRole('button', { name: 'Manage' }).click();
  const manage = page.getByRole('dialog', { name: 'Manage Rowan' });
  await expect(manage).toBeVisible();

  for (const [button, title, confirm, path] of [
    ['Sign out everywhere', 'Sign Rowan out everywhere?', 'Sign out', 'admin/kick.php'],
    ['Ban', 'Ban Rowan?', 'Ban Rowan', 'admin/ban.php'],
  ]) {
    await manage.getByRole('button', { name: button, exact: true }).click();
    const ask = page.getByRole('dialog', { name: title });
    await expect(ask.getByRole('button', { name: 'Cancel' })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(ask).toHaveCount(0);
    expect(posts(requests, path)).toEqual([]);
    await manage.getByRole('button', { name: button, exact: true }).click();
    await page.getByRole('dialog', { name: title }).getByRole('button', { name: confirm, exact: true }).click();
    await expect.poll(() => posts(requests, path).length).toBe(1);
    if (path === 'admin/ban.php') break;
  }
  expect(posts(requests, 'admin/ban.php')[0].body).toMatchObject({ target_user_id: 2, expires_at: null });

  // The dialog closed after the ban; reopen for the password.
  await page.locator('#user-list .admin-list-item', { hasText: 'Rowan' }).getByRole('button', { name: 'Manage' }).click();
  await manage.getByLabel('New password').fill('a new password for Rowan');
  await manage.getByRole('button', { name: 'Reset password' }).click();
  const reset = page.getByRole('dialog', { name: 'Reset Rowan’s password?' });
  await expect(reset).toContainText('Tell them the new password another way.');
  await axe(page, 'reset dialog');
  await reset.getByRole('button', { name: 'Reset password' }).click();
  await expect.poll(() => posts(requests, 'admin/reset-password.php').map((entry) => entry.body)).toEqual([{ target_user_id: 2, new_password: 'a new password for Rowan' }]);
  expect(native).toBe(0);
});

test('a ban with an end date says until when', async ({ page }) => {
  await fixture(page);
  await open(page);
  await page.locator('#user-list .admin-list-item', { hasText: 'Rowan' }).getByRole('button', { name: 'Manage' }).click();
  const manage = page.getByRole('dialog', { name: 'Manage Rowan' });
  await manage.getByLabel(/Ban until/).fill('2026-10-11T10:00');
  await manage.getByRole('button', { name: 'Ban', exact: true }).click();
  await expect(page.getByRole('dialog', { name: 'Ban Rowan?' })).toContainText(/can’t sign in until .*2026/);
});

// ---- Rooms ----

test('members: Remove asks in the styled dialog; Make owner… only where the server offers it', async ({ page }) => {
  const requests = await fixture(page);
  await openRooms(page);
  await expect(memberRow(page, 'Morgan').getByRole('button')).toHaveCount(1); // the name only: owners keep their row
  await expect(memberRow(page, 'Rowan').getByRole('button', { name: 'Make owner…' })).toBeVisible();
  await expect(memberRow(page, 'Priya').getByRole('button', { name: 'Make owner…' })).toHaveCount(0);

  await memberRow(page, 'Priya').getByRole('button', { name: 'Remove' }).click();
  const remove = page.getByRole('dialog', { name: 'Remove Priya from #Garden?' });
  await expect(remove).toContainText('in a private one they need a new invitation');
  await remove.getByRole('button', { name: 'Remove' }).click();
  await expect.poll(() => posts(requests, 'admin/rooms/remove-member.php').map((entry) => entry.body)).toEqual([{ room_id: 4, target_user_id: 3 }]);
});

test('Make owner… hands the room on after the sign-in check', async ({ page }) => {
  const requests = await fixture(page, { transfer: [refusal(403, 'step_up_required'), { room }] });
  await openRooms(page);
  await memberRow(page, 'Rowan').getByRole('button', { name: 'Make owner…' }).click();
  const ask = page.getByRole('dialog', { name: 'Make Rowan the owner of #Garden?' });
  await expect(ask).toContainText('The current owner stays in the room as a moderator.');
  await ask.getByRole('button', { name: 'Hand over' }).click();
  const check = page.getByRole('dialog', { name: 'Confirm this sensitive action' });
  await check.getByLabel('Current password').fill('correct horse battery staple');
  await check.getByRole('button', { name: 'Verify password' }).click();
  await expect(page.locator('#toast-region')).toContainText('Rowan owns #Garden now.');
  expect(posts(requests, 'rooms/transfer-ownership.php').map((entry) => entry.body)).toEqual([{ room_id: 4, target_user_id: 2 }, { room_id: 4, target_user_id: 2 }]);
});

test('a stale offer is explained, without naming a reason the viewer can’t know', async ({ page }) => {
  await fixture(page, { transfer: [refusal(409, 'transfer_target_not_eligible')] });
  await openRooms(page);
  await memberRow(page, 'Rowan').getByRole('button', { name: 'Make owner…' }).click();
  await page.getByRole('dialog', { name: 'Make Rowan the owner of #Garden?' }).getByRole('button', { name: 'Hand over' }).click();
  await expect(page.locator('#admin-error')).toHaveText('Rowan can’t own this room right now (guests, closing or banned accounts can’t).');
});

test('Take ownership…: offered by the server, says the minimum age applies, posts your own id', async ({ page }) => {
  const requests = await fixture(page);
  await openRooms(page);
  await page.getByRole('button', { name: 'Take ownership…' }).click();
  const ask = page.getByRole('dialog', { name: 'Take ownership of #Garden?' });
  await expect(ask).toContainText('the room’s minimum age applies to you');
  await ask.getByRole('button', { name: 'Take ownership' }).click();
  await expect(page.locator('#toast-region')).toContainText('You own #Garden now.');
  expect(posts(requests, 'rooms/transfer-ownership.php').map((entry) => entry.body)).toEqual([{ room_id: 4, target_user_id: 1 }]);
});

test('Take ownership… stays hidden when the server doesn’t offer it', async ({ page }) => {
  await fixture(page, { snapshotRoom: { ...room, can_take_ownership: false } });
  await openRooms(page);
  await expect(page.getByRole('button', { name: 'Take ownership…' })).toBeHidden();
});

test('Delete room asks in the styled dialog; Restore stays one step', async ({ page }) => {
  const requests = await fixture(page);
  await openRooms(page);
  await page.getByRole('button', { name: 'Delete room' }).click();
  const ask = page.getByRole('dialog', { name: 'Delete #Garden?' });
  await expect(ask).toContainText('You can restore it under Deleted rooms');
  await page.keyboard.press('Escape');
  expect(posts(requests, 'rooms/delete.php')).toEqual([]);
  await page.getByRole('button', { name: 'Delete room' }).click();
  await page.getByRole('dialog', { name: 'Delete #Garden?' }).getByRole('button', { name: 'Delete room' }).click();
  await expect.poll(() => posts(requests, 'rooms/delete.php').map((entry) => entry.body)).toEqual([{ room_id: 4 }]);
  await page.locator('#deleted-room-list').getByRole('button', { name: 'Restore' }).click();
  await expect.poll(() => posts(requests, 'rooms/restore.php').map((entry) => entry.body)).toEqual([{ room_id: 9 }]);
});

test('invitations: search, invite and revoke', async ({ page }) => {
  const requests = await fixture(page);
  await openRooms(page);
  await page.locator('#invitation-search').fill('Ki');
  await page.locator('#invitation-search-form').getByRole('button', { name: 'Search' }).click();
  await page.locator('#invitation-search-results').getByRole('button', { name: 'Invite' }).click();
  await expect.poll(() => posts(requests, 'rooms/invite.php').map((entry) => entry.body)).toEqual([{ room_id: 4, target_user_id: 7 }]);
  await page.locator('#room-invitation-list').getByRole('button', { name: 'Revoke' }).click();
  await expect.poll(() => posts(requests, 'admin/rooms/revoke-invitation.php').map((entry) => entry.body)).toEqual([{ room_id: 4, target_user_id: 6 }]);
});

// ---- Audit ----

test('the audit log pages back with Load older entries', async ({ page }) => {
  await fixture(page);
  await open(page);
  await page.locator('#admin-tabs').getByRole('button', { name: 'Audit' }).click();
  await expect(page.locator('#audit-list .audit-entry')).toHaveCount(50);
  await expect(page.locator('#audit-list .audit-entry').first()).toContainText('room.ownership_transferred');
  await page.getByRole('button', { name: 'Load older entries' }).click();
  await expect(page.getByRole('button', { name: 'Load older entries' })).toBeHidden();
});

// ---- axe, reflow and the baseline ----

for (const [scheme, theme] of [['lantern', 'light'], ['lantern', 'dark'], ['dusk', 'dark']]) {
  test(`${scheme} ${theme}: Administration passes axe at 1280 and 360, and reflows at 320`, async ({ page }) => {
    await page.addInitScript(([chosenScheme, chosenTheme]) => {
      localStorage.setItem('chitchat-theme', chosenTheme);
      localStorage.setItem('chitchat-scheme', chosenScheme);
    }, [scheme, theme]);
    await fixture(page);
    await open(page);
    await axe(page, '1280 users');
    await page.locator('#admin-tabs').getByRole('button', { name: 'Rooms' }).click();
    await expect(page.locator('#room-member-list')).toContainText('Rowan');
    await axe(page, '1280 rooms');
    await page.locator('#admin-tabs').getByRole('button', { name: 'Audit' }).click();
    await axe(page, '1280 audit');
    await openRooms(page, 360);
    await axe(page, '360 rooms');
    await page.setViewportSize({ width: 320, height: 700 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  });
}

test('baseline: Administration, Rooms, at 1280 in Lantern dark', async ({ page, browserName }) => {
  test.skip(browserName !== 'chromium' || process.platform !== 'linux', 'Visual baselines are pinned Chromium on Linux (CI).');
  await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce' });
  await fixture(page);
  await openRooms(page);
  await expect(page.locator('#room-invitation-list')).toContainText('Sam');
  await page.mouse.move(0, 0);
  await expect(page).toHaveScreenshot('admin-rooms-desktop.png', { animations: 'disabled', caret: 'hide', fullPage: true, maxDiffPixelRatio: 0.003, threshold: 0.2 });
});
