import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// Reporting a person (#159), presentation only: real PHP templates and
// browser modules against intercepted API responses.
const viewer = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const peer = { id: 2, username: 'Rowan' };
const moderator = { id: 3, username: 'Morgan', roles: ['moderator'], session_version: 1 };
const rooms = [{
  id: 1, name: 'The living room', key: 'room-0', visibility: 'public', member_role: 'member',
  minimum_age: 0, inactivity_timeout_seconds: 0, info_line: 'A place for everyday conversations.',
}];
const messages = [['Rowan', 'Anyone else taking the slow route through Sunday?'], ['Jamie', 'Coffee and no plans.']]
  .map(([username, body], index) => ({
    id: index + 1, room_id: 1, user_id: username === 'Jamie' ? 1 : 2, sender_id: username === 'Jamie' ? 1 : 2, username, body, type: 'text',
    deleted: false, mentions: [], created_at: `2026-11-08T10:0${index}:00Z`, reactions: [],
  }));
const peerProfile = {
  id: 2, username: 'Rowan', member_since: '2026-07-01T10:00:00Z', badge: null, has_avatar: true,
  avatar_version: 3, can_remove_avatar: false, ignored: false, can_report: true, moderation: null,
};

const personCase = {
  id: 42, subject_kind: 'user', status: 'open', report_count: 2, subject: { id: 2, username: 'Rowan' },
  room: { id: 1, name: 'The living room' }, first_reported_at: '2026-11-08T10:12:00Z', last_reported_at: '2026-11-08T10:40:00Z',
  assigned_to: null,
};
const messageCase = {
  id: 41, subject_kind: 'message', status: 'open', report_count: 1, subject: { id: 2, username: 'Rowan' },
  message_kind: 'room', message_id: 1, room: { id: 1, name: 'The living room' },
  first_reported_at: '2026-11-08T09:00:00Z', last_reported_at: '2026-11-08T09:00:00Z', assigned_to: null,
};
const avatarPixel = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256"><rect width="256" height="256" fill="#c96"/><circle cx="128" cy="100" r="50" fill="#642"/></svg>');

async function fixture(page, { user = viewer, profile = peerProfile, overrides = {}, requests = [] } = {}) {
  let blocked = false;
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace('/api/v1/', '');
    if (request.method() === 'POST') requests.push({ path, body: request.postDataJSON?.() ?? null });
    if (path === 'direct-messages/block.php') blocked = true;
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path in overrides) {
      const value = typeof overrides[path] === 'function' ? overrides[path](request) : overrides[path];
      if (value?.status) return route.fulfill({ status: value.status, json: value.json });
      return route.fulfill({ json: value });
    }
    const responses = {
      'session.php': { user, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'rooms/list.php': { rooms },
      'rooms/messages.php': { messages },
      'rooms/message-mutations.php': { messages: messages.map((message) => ({ ...message, can_edit: message.user_id === 1, can_delete: message.user_id === 1, can_report: message.user_id !== 1 })) },
      'attachments/metadata.php': { attachments: [] },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [viewer, peer].map((person) => ({ ...person, idle_seconds: 0 })) },
      'rooms/mentionable-users.php': { users: [peer] },
      'rooms/pings.php': { pings: [] },
      'typing.php': { signalled: true },
      'rooms/read.php': { last_read_message_id: 2 },
      'users/profile.php': { avatars_available: true, profile },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [{ user: peer, unread_count: 0, last_message: { body: 'See you!', outgoing: false } }] },
      'direct-messages/block-status.php': { relationship: { blocked_by_me: blocked, messaging_available: !blocked } },
      'direct-messages/history.php': { messages: messages.map((message) => ({ ...message, sender: { username: message.username }, outgoing: message.username === 'Jamie' })) },
      'direct-messages/read.php': {},
      'direct-messages/attachments/metadata.php': { attachments: [] },
      'direct-messages/message-mutations.php': { messages: [] },
      'reports/user.php': { report: { id: 7, case_id: 42 } },
      'users/ignore.php': { ignored: true },
      'direct-messages/block.php': { relationship: { blocked_by_me: true, messaging_available: false } },
    };
    if (!(path in responses)) throw new Error(`Missing fixture: ${path}`);
    await route.fulfill({ json: responses[path] });
  });
}

async function accessible(page) {
  const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  expect(result.violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) }))).toEqual([]);
}

async function noOverflow(page) {
  await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  const width = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
  expect(width[0]).toBeLessThanOrEqual(width[1]);
}

async function openRowansCard(page) {
  await page.goto('/');
  // Phones open on the Rooms list with nothing opened behind it (S2c): open the room as a person would.
  if ((page.viewportSize()?.width ?? 1280) < 768) await page.locator('#room-list .room-button').first().click();
  await expect(page.locator('#message-list .message').first()).toBeAttached();
  await expect(page.locator('.message').first()).toBeVisible();
  await page.locator('.message .user-name', { hasText: 'Rowan' }).first().click();
  await expect(page.locator('#name-menu')).toBeVisible();
}

for (const theme of ['dark', 'light']) {
  test(`${theme}: the profile card reports a person from a room, then offers ignore and block`, async ({ page }, testInfo) => {
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await page.emulateMedia({ colorScheme: theme, reducedMotion: 'reduce' });
    await page.setViewportSize({ width: 1440, height: 1000 });
    const requests = [];
    await fixture(page, { requests });
    await openRowansCard(page);
    const item = page.locator('#name-menu').getByRole('button', { name: 'Report Rowan' });
    await expect(item).toBeVisible();
    await item.click();

    const dialog = page.getByRole('dialog', { name: 'Report Rowan' });
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('Moderators see Rowan\'s name and current profile picture. Nothing from your conversations is shared.');
    await expect(dialog).toContainText('Reported from #The living room. Moderators of this room can see it too.');
    const options = await dialog.locator('#message-report-category option').allTextContents();
    expect(options.slice(0, 2)).toEqual(['Offensive username', 'Offensive profile picture']);
    await expect(dialog.getByRole('combobox', { name: 'What\'s wrong?' })).toBeFocused();
    await accessible(page);
    await page.screenshot({ path: testInfo.outputPath(`report-person-${theme}.png`) });

    await dialog.getByRole('combobox').selectOption('offensive_username');
    await dialog.getByRole('textbox', { name: /Anything moderators should know/ }).fill('The name is a slur.');
    await expect(dialog.locator('#message-report-count')).toHaveText('19 / 1000');
    await dialog.getByRole('button', { name: 'Report', exact: true }).click();

    const done = page.getByRole('dialog', { name: 'Thanks, your report is in' });
    await expect(done).toBeVisible();
    await expect(done.getByRole('heading', { name: 'Thanks, your report is in' })).toBeFocused();
    await expect(done).toContainText('You won’t get a message about the outcome.');
    expect(requests.find((entry) => entry.path === 'reports/user.php').body).toEqual({
      user_id: 2, category: 'offensive_username', details: 'The name is a slur.', context: { room_id: 1 },
    });
    await accessible(page);
    await page.screenshot({ path: testInfo.outputPath(`report-person-done-${theme}.png`) });

    await done.getByRole('button', { name: 'Ignore in rooms' }).click();
    await expect(done.locator('#report-follow-up-status')).toHaveText('Rowan is ignored in rooms.');
    await done.getByRole('button', { name: 'Block direct messages' }).click();
    await expect(done.locator('#report-follow-up-status')).toHaveText('Rowan can no longer send you direct messages.');
    await expect(done.getByRole('button', { name: 'Done' })).toBeFocused();
    await done.getByRole('button', { name: 'Done' }).click();
    await expect(done).toBeHidden();
  });
}

test('without a picture the dialog says so, and an earlier report is acknowledged', async ({ page }) => {
  await fixture(page, {
    profile: { ...peerProfile, has_avatar: false },
    overrides: { 'reports/user.php': { status: 409, json: { error: { code: 'already_reported', message: 'Already reported.' } } } },
  });
  await openRowansCard(page);
  await page.locator('#name-menu').getByRole('button', { name: 'Report Rowan' }).click();
  const dialog = page.getByRole('dialog', { name: 'Report Rowan' });
  await expect(dialog).toContainText('Moderators see Rowan\'s name. Nothing from your conversations is shared.');
  await expect(dialog.locator('option[value="offensive_profile_picture"]')).toHaveCount(0);
  await dialog.getByRole('button', { name: 'Report', exact: true }).click();
  await expect(page.getByRole('dialog', { name: 'You\'ve already reported Rowan' })).toContainText('Your earlier report is kept with its case, so there’s no need to send another.');
});

test('a person you already reported (even on a closed case) shows a neutral note instead of Report', async ({ page }) => {
  await fixture(page, { profile: { ...peerProfile, reported_by_me: true } });
  await openRowansCard(page);
  await expect(page.locator('#name-menu')).toContainText('You’ve already reported Rowan from here.');
  await expect(page.locator('#name-menu').getByRole('button', { name: /Report/ })).toHaveCount(0);
});

test('the card offers no report when the server says no', async ({ page }) => {
  await fixture(page, { profile: { ...peerProfile, can_report: false } });
  await openRowansCard(page);
  await expect(page.locator('#name-menu').getByRole('button', { name: /Report/ })).toHaveCount(0);
});

// Each refusal on its own page (#304): swapping fixtures and navigating the same page to /
// again could abort the second load (net::ERR_ABORTED) while the first was still busy.
for (const [code, status, extra, sentence] of [
  ['report_not_allowed', 403, {}, 'You can’t report this person from here.'],
  ['rate_limited', 429, { retry_after_seconds: 300 }, 'You’ve sent several reports quickly. Try again in 5 minutes.'],
  ['report_snapshot_unavailable', 409, {}, 'The report couldn’t be saved just now, so nothing was sent. Try again in a moment.'],
]) {
  test(`a refused report (${code}) is explained in the dialog`, async ({ page }) => {
    await fixture(page, { overrides: { 'reports/user.php': { status, json: { error: { code, message: 'Refused.', ...extra } } } } });
    await openRowansCard(page);
    await page.locator('#name-menu').getByRole('button', { name: 'Report Rowan' }).click();
    const dialog = page.getByRole('dialog', { name: 'Report Rowan' });
    await dialog.getByRole('button', { name: 'Report', exact: true }).click();
    await expect(dialog.locator('#message-report-error')).toHaveText(sentence);
  });
}

test('message reports keep their own wording', async ({ page }) => {
  await fixture(page);
  await page.goto('/');
  await page.locator('.message').first().getByRole('button', { name: 'Report', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Report message' });
  await expect(dialog).toContainText('Moderators see a copy of this message only, not the rest of the conversation.');
  await expect(dialog.locator('option[data-person-only]')).toHaveCount(0);
  await expect(dialog.locator('#report-person')).toBeHidden();
});

test('direct messages: report from the conversation header, reviewed globally', async ({ page }) => {
  const requests = [];
  await fixture(page, { requests });
  await page.goto('/messages.php?with=2');
  await expect(page.locator('.dm-message').first()).toBeVisible();
  await page.getByRole('button', { name: 'Report Rowan' }).click();
  const dialog = page.getByRole('dialog', { name: 'Report Rowan' });
  await expect(dialog).toContainText('Global moderators review this report.');
  await dialog.getByRole('button', { name: 'Report', exact: true }).click();
  await expect(page.getByRole('dialog', { name: 'Thanks, your report is in' })).toBeVisible();
  expect(requests.find((entry) => entry.path === 'reports/user.php').body.context).toBeNull();
  await page.getByRole('button', { name: 'Block direct messages' }).click();
  await page.getByRole('button', { name: 'Done' }).click();
  await expect(page.getByRole('button', { name: 'Unblock Rowan' })).toBeVisible();
  await accessible(page);
});

test('phones get the dialog as a bottom sheet', async ({ page }, testInfo) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await fixture(page);
  await openRowansCard(page);
  await page.locator('#name-menu').getByRole('button', { name: 'Report Rowan' }).click();
  const dialog = page.getByRole('dialog', { name: 'Report Rowan' });
  const box = await dialog.boundingBox();
  expect(Math.round(box.width)).toBe(360);
  expect(Math.round(box.y + box.height)).toBe(740);
  await expect(dialog.getByRole('button', { name: 'Report', exact: true })).toBeInViewport();
  await noOverflow(page);
  // S7: the sheet passes axe as a sheet, too.
  await accessible(page);
  await page.screenshot({ path: testInfo.outputPath('report-person-phone.png') });
});

test('a person case from a removed room keeps its name, marked as removed', async ({ page }) => {
  const removedCase = { ...personCase, id: 43, room: { id: 9, name: 'Old room', available: false } };
  const requests = [];
  await fixture(page, {
    user: moderator,
    requests,
    profile: { ...peerProfile, moderation: { room: null, everywhere: { can_mute: true, mute: null, can_ban: false, ban: null } } },
    overrides: {
      'moderation/cases.php': { cases: [removedCase], has_more: false, next_before_id: null },
      'moderation/case.php': { case: { ...removedCase, snapshot: { username: 'Rowan', avatar_url: null }, avatar_changed_since_report: false, reports: [] } },
    },
  });
  const profileRequests = [];
  page.on('request', (request) => { if (request.url().includes('/users/profile.php')) profileRequests.push(new URL(request.url())); });
  await page.goto('/moderation.php');
  await expect(page.locator('.moderation-case-button').first()).toContainText('Person: Rowan · # Old room · removed');
  await expect(page.locator('#moderation-case-context')).toHaveText('Reported person · from # Old room · removed');
  await expect(page.locator('#moderation-detail a', { hasText: 'Old room' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Mute…' })).toBeVisible();
  expect(profileRequests.every((url) => !url.searchParams.has('room_id'))).toBe(true);
});

test('moderators review person cases with a blurred picture and person actions', async ({ page }, testInfo) => {
  const requests = [];
  await fixture(page, {
    user: moderator,
    requests,
    profile: { ...peerProfile, can_remove_avatar: true, moderation: { room: { id: 1, name: 'The living room', can_mute: true, mute: null }, everywhere: null } },
    overrides: {
      'moderation/cases.php': (request) => {
        const kind = new URL(request.url()).searchParams.get('subject_kind');
        const cases = kind === 'user' ? [personCase] : kind === 'message' ? [messageCase] : [personCase, messageCase];
        return { cases, has_more: false, next_before_id: null };
      },
      'moderation/case.php': (request) => (new URL(request.url()).searchParams.get('case_id') === '42'
        ? { case: {
          ...personCase,
          snapshot: { username: 'Rowan_x', avatar_url: avatarPixel },
          avatar_changed_since_report: true,
          subject: { id: 2, username: 'Rowan' },
          reports: [
            { reporter: { id: 1, username: 'Jamie' }, category: 'offensive_username', details: 'The name is a slur.', created_at: '2026-11-08T10:12:00Z' },
            { reporter: { id: 4, username: 'Alex' }, category: 'offensive_profile_picture', details: null, created_at: '2026-11-08T10:40:00Z' },
          ],
        } }
        : { case: { ...messageCase, reports: [{ reporter: { id: 1, username: 'Jamie' }, category: 'spam', details: null, created_at: '2026-11-08T09:00:00Z', evidence_body: 'Buy now', evidence: {} }] } }),
      'account/avatar/remove.php': {},
      'moderation/resolve.php': (request) => ({ case: { ...personCase, status: 'resolved', resolution_code: request.postDataJSON().resolution_code, resolved_by: moderator, reports: [] } }),
    },
  });
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto('/moderation.php');
  await expect(page.locator('.moderation-case-button').first()).toContainText('Person: Rowan · # The living room');
  await page.getByRole('radio', { name: 'People' }).check();
  await expect(page.locator('.moderation-case-button')).toHaveCount(1);

  await expect(page.locator('#moderation-case-context')).toHaveText('Reported person · from # The living room');
  await expect(page.locator('#person-evidence-then')).toHaveText('Rowan_x');
  await expect(page.locator('#person-evidence-now')).toHaveText('Rowan');
  await expect(page.locator('#person-evidence-changed')).toBeVisible();
  const picture = page.locator('#person-evidence-picture img');
  expect(await picture.evaluate((image) => getComputedStyle(image).filter)).toContain('blur');
  await expect(page.locator('.moderation-report-card')).toHaveCount(2);
  await expect(page.locator('.moderation-report-card').first()).toContainText('Offensive username');
  await expect(page.locator('.moderation-evidence')).toHaveCount(0);
  await accessible(page);
  await page.screenshot({ path: testInfo.outputPath('moderation-person-case.png'), fullPage: true });

  await page.getByRole('button', { name: 'Show picture' }).click();
  await expect(page.getByRole('img', { name: 'Profile picture of Rowan_x when first reported' })).toBeFocused();
  expect(await picture.evaluate((image) => getComputedStyle(image).filter)).toBe('none');

  await expect(page.getByRole('button', { name: 'Mute…' })).toBeVisible();
  await page.getByRole('button', { name: 'Remove profile picture' }).click();
  await page.getByRole('dialog', { name: /Remove Rowan's profile picture/ }).getByRole('button', { name: 'Remove picture' }).click();
  await expect(page.locator('#moderation-closed-summary')).toContainText('profile picture removed');
  expect(requests.find((entry) => entry.path === 'moderation/resolve.php').body.resolution_code).toBe('profile_picture_removed');

  await page.getByRole('radio', { name: 'Messages' }).check();
  await expect(page.locator('.moderation-case-button')).toHaveCount(1);
  await expect(page.locator('#moderation-person-evidence')).toBeHidden();
  await expect(page.locator('.moderation-evidence')).toHaveText('Buy now');

  // The real-server reflow gate checks the queue at 320px, with and without cases.
  await page.setViewportSize({ width: 320, height: 740 });
  await page.getByRole('radio', { name: 'People' }).check();
  await noOverflow(page);
  await page.route('**/api/v1/moderation/cases.php?**', (route) => route.fulfill({ json: { cases: [], has_more: false, next_before_id: null } }));
  await page.getByRole('radio', { name: 'All' }).check();
  await expect(page.locator('#moderation-status')).toHaveText('No cases match this filter.');
  await noOverflow(page);
});

// ---- S6c (#172): the queue and the card in the Dusk frame ----

function personQueue(page, requests) {
  return fixture(page, {
    user: moderator,
    requests,
    profile: { ...peerProfile, can_remove_avatar: true, moderation: { room: { id: 1, name: 'The living room', can_mute: true, mute: null }, everywhere: null } },
    overrides: {
      'moderation/cases.php': { cases: [personCase], has_more: false, next_before_id: null },
      'moderation/case.php': { case: {
        ...personCase,
        snapshot: { username: 'Rowan_x', avatar_url: avatarPixel },
        avatar_changed_since_report: true,
        subject: { id: 2, username: 'Rowan' },
        reports: [
          { reporter: { id: 1, username: 'Jamie' }, category: 'offensive_username', details: 'The name is a slur.', created_at: '2026-11-08T10:12:00Z' },
          { reporter: { id: 4, username: 'Alex' }, category: 'offensive_profile_picture', details: null, created_at: '2026-11-08T10:40:00Z' },
        ],
      } },
      'moderation/resolve.php': (request) => ({ case: { ...personCase, status: request.postDataJSON().status, resolution_code: request.postDataJSON().resolution_code, resolved_by: moderator, reports: [] } }),
    },
  });
}

test('Dismiss asks in the styled dialog, not window.confirm; Cancel closes nothing', async ({ page }) => {
  const requests = [];
  let native = 0;
  page.on('dialog', (dialog) => { native += 1; void dialog.dismiss(); });
  await personQueue(page, requests);
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto('/moderation.php');
  await expect(page.locator('#moderation-case-context')).toHaveText('Reported person · from # The living room');
  await expect(page.getByRole('region', { name: 'What a report shows' })).toContainText('It never opens the rest of the conversation.');
  await page.getByRole('button', { name: 'Dismiss: no violation' }).click();
  const ask = page.getByRole('dialog', { name: 'Dismiss case #42?' });
  await expect(ask).toContainText('It closes as no violation, with your note if you wrote one.');
  await expect(ask.getByRole('button', { name: 'Cancel' })).toBeFocused();
  await page.keyboard.press('Escape');
  expect(requests.filter((entry) => entry.path === 'moderation/resolve.php')).toEqual([]);
  await page.getByRole('button', { name: 'Dismiss: no violation' }).click();
  await ask.getByRole('button', { name: 'Dismiss case' }).click();
  await expect.poll(() => requests.find((entry) => entry.path === 'moderation/resolve.php')?.body).toMatchObject({ case_id: 42, status: 'dismissed', resolution_code: 'no_violation' });
  expect(native).toBe(0);
});

test('the card’s Remove profile picture asks in the styled dialog, as the queue does', async ({ page }) => {
  const requests = [];
  let native = 0;
  page.on('dialog', (dialog) => { native += 1; void dialog.dismiss(); });
  await fixture(page, { user: moderator, requests, profile: { ...peerProfile, can_remove_avatar: true }, overrides: { 'account/avatar/remove.php': {} } });
  await openRowansCard(page);
  await page.locator('#name-menu').getByRole('button', { name: 'Remove profile picture' }).click();
  const ask = page.getByRole('dialog', { name: "Remove Rowan's profile picture?" });
  await expect(ask).toContainText('Their initials show instead.');
  await ask.getByRole('button', { name: 'Remove picture' }).click();
  await expect(page.locator('#name-menu')).toContainText('Profile picture removed.');
  expect(requests.find((entry) => entry.path === 'account/avatar/remove.php')?.body).toEqual({ user_id: 2 });
  expect(native).toBe(0);
});

test('baseline: the moderation queue with a person case, at 1280 in Lantern dark', async ({ page, browserName }) => {
  test.skip(browserName !== 'chromium' || process.platform !== 'linux', 'Visual baselines are pinned Chromium on Linux (CI).');
  await page.clock.setFixedTime(new Date('2026-11-12T12:00:00Z'));
  await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce' });
  await personQueue(page, []);
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto('/moderation.php');
  await expect(page.locator('#person-evidence-then')).toHaveText('Rowan_x');
  await expect(page.locator('.moderation-report-card')).toHaveCount(2);
  await page.waitForLoadState('networkidle');
  await page.mouse.move(0, 0);
  await expect(page).toHaveScreenshot('moderation-desktop.png', { animations: 'disabled', caret: 'hide', fullPage: true, maxDiffPixelRatio: 0.003, threshold: 0.2 });
});
