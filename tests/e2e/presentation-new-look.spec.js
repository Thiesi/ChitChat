import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// The one-time "new look" note after the Lantern recolour (S0, #167):
// members without a stored scheme only, dismissible, gone once a scheme is
// chosen. Presentation only: real templates against intercepted API responses.
const member = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const guest = { id: 9, username: 'Guest 0042', roles: [], guest: true, session_version: 1 };
const rooms = [{
  id: 1, name: 'The living room', key: 'room-0', visibility: 'public', member_role: 'member',
  minimum_age: 0, inactivity_timeout_seconds: 0, info_line: 'A place for everyday conversations.', guest_access: 'write',
}];

async function fixture(page, user) {
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1/', '');
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    const responses = {
      'session.php': { user, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
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
}

async function accessible(page) {
  const result = await new AxeBuilder({ page }).include('#new-look-note').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  expect(result.violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) }))).toEqual([]);
}

for (const theme of ['dark', 'light']) {
  test(`${theme}: members without a stored scheme see the note once, and can dismiss it`, async ({ page }) => {
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await page.emulateMedia({ colorScheme: theme, reducedMotion: 'reduce' });
    await fixture(page, member);
    await page.goto('/');
    const note = page.locator('#new-look-note');
    await expect(note).toBeVisible();
    await expect(note).toHaveAttribute('role', 'status');
    await expect(note).toContainText('has a new look. Prefer the previous one? Choose Lounge in Appearance.');
    await expect(note.getByRole('link', { name: 'Appearance' })).toHaveAttribute('href', '/account.php#appearance-heading');
    // Never takes the initial focus.
    expect(await note.evaluate((element) => element.contains(document.activeElement))).toBe(false);
    await accessible(page);

    const dismiss = note.getByRole('button', { name: 'Dismiss' });
    await dismiss.focus();
    await page.keyboard.press('Enter');
    await expect(note).toBeHidden();
    await page.reload();
    await expect(page.locator('#chat-shell')).toBeVisible();
    await expect(note).toBeHidden();
  });
}

test('choosing any scheme retires the note', async ({ page }) => {
  await fixture(page, member);
  await page.goto('/');
  const note = page.locator('#new-look-note');
  await expect(note).toBeVisible();
  // Colour schemes live on You since the account menu went (S2b).
  await page.goto('/account.php');
  await page.locator('label.scheme-option', { hasText: 'Dusk' }).first().click();
  await page.goto('/');
  await expect(page.locator('#chat-shell')).toBeVisible();
  await expect(note).toBeHidden();
  expect(await page.evaluate(() => document.documentElement.dataset.scheme)).toBe('dusk');
});

test('choosing Lantern, the default, is stored like any other choice', async ({ page }) => {
  await fixture(page, member);
  await page.goto('/');
  await expect(page.locator('#new-look-note')).toBeVisible();
  expect(await page.evaluate(() => localStorage.getItem('chitchat-scheme'))).toBeNull();
  await page.goto('/account.php');
  // Lantern starts out checked (the default), so switch away and back.
  await page.locator('label.scheme-option', { hasText: 'Dusk' }).first().click();
  await page.locator('label.scheme-option', { hasText: 'Lantern' }).first().click();
  await expect(page.locator('#new-look-note')).toBeHidden();
  expect(await page.evaluate(() => document.documentElement.dataset.scheme)).toBeUndefined();
  // Nothing stored means only "never chose", so a deliberate Lantern pick is kept.
  expect(await page.evaluate(() => localStorage.getItem('chitchat-scheme'))).toBe('lantern');
  // The stored choice alone keeps the note away, even without the seen flag.
  await page.evaluate(() => localStorage.removeItem('chitchat-new-look-seen'));
  await page.goto('/');
  await expect(page.locator('#chat-shell')).toBeVisible();
  await expect(page.locator('#new-look-note')).toBeHidden();
  expect(await page.evaluate(() => window.chitchatTheme.hasStoredScheme())).toBe(true);
});

test('guests and people with a stored scheme never see it', async ({ page }) => {
  await fixture(page, guest);
  await page.goto('/');
  await expect(page.locator('#chat-shell')).toBeVisible();
  await expect(page.locator('#new-look-note')).toBeHidden();

  await page.unroute('**/api/v1/**');
  await fixture(page, member);
  await page.addInitScript(() => localStorage.setItem('chitchat-scheme', 'ember'));
  await page.goto('/');
  await expect(page.locator('#chat-shell')).toBeVisible();
  await expect(page.locator('#new-look-note')).toBeHidden();
});

test('the note and the recovery-key nudge share the chat without breaking its layout', async ({ page }) => {
  await fixture(page, { ...member, recovery_key: { exists: false, created_at: null } });
  for (const size of [{ width: 1280, height: 800 }, { width: 320, height: 640 }]) {
    await page.setViewportSize(size);
    await page.goto('/');
    await expect(page.locator('#new-look-note')).toBeVisible();
    await expect(page.locator('#recovery-nudge')).toBeVisible();
    await expect(page.locator('#room-list .room-button').first()).toBeAttached();
    // Phones open on the Rooms list (S2c): open the room as a person would.
    if ((page.viewportSize()?.width ?? 1280) < 768) await page.locator('#room-list .room-button').first().click();
    // Each strip gets its own row: neither stretches into the chat's row,
    // and the composer stays whole and on screen.
    for (const strip of ['#new-look-note', '#recovery-nudge']) {
      const box = await page.locator(strip).boundingBox();
      expect(box.height, strip).toBeLessThan(size.height * 0.2);
    }
    const composer = await page.locator('#composer-input').boundingBox();
    expect(composer).not.toBeNull();
    expect(composer.height).toBeGreaterThan(20);
    expect(composer.y + composer.height).toBeLessThanOrEqual(size.height);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(size.width);
  }
});
