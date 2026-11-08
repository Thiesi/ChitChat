import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// Recovery keys and the self-service password reset (#155), presentation
// only: real PHP templates and browser modules against intercepted API
// responses.
const KEY = 'K7QF3MZD9XHV2TRPW8BN6CJA';
const NEW_KEY = 'Q4MN8RTX2VBC7HJK5PWD9FGS';
const keyless = { exists: false, created_at: null };
const viewer = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1, recovery_key: { exists: true, created_at: '2026-11-01T10:00:00Z' } };
const rooms = [{
  id: 1, name: 'The living room', key: 'room-0', visibility: 'public', member_role: 'member',
  minimum_age: 0, inactivity_timeout_seconds: 0, info_line: 'A place for everyday conversations.',
}];

async function fixture(page, { user = null, overrides = {}, requests = [] } = {}) {
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace('/api/v1/', '');
    if (request.method() === 'POST') requests.push({ path, body: request.postDataJSON?.() ?? null });
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path in overrides) {
      const value = typeof overrides[path] === 'function' ? overrides[path](request) : overrides[path];
      if (Number.isInteger(value?.status)) return route.fulfill({ status: value.status, json: value.json });
      return route.fulfill({ json: value });
    }
    const responses = {
      'session.php': { user, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'registration-challenge.php': { challenge: null },
      'password-reset/challenge.php': { challenge: null },
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
      'users/profile.php': { avatars_available: true, profile: { id: 1, username: 'Jamie', member_since: '2026-07-01T10:00:00Z', badge: null, has_avatar: false, avatar_version: null, can_remove_avatar: false } },
      'account/identities/list.php': { providers: [], identities: [], has_password: true },
      'account/mfa/status.php': { mfa: { enabled: false, available: false, credentials: [] } },
      'register.php': { user: { ...viewer, recovery_key: { exists: true, created_at: '2026-11-08T10:00:00Z' } }, csrf_token: 'fixture-2', recovery_key: KEY },
      'password-reset.php': { status: 'complete', user: viewer, csrf_token: 'fixture-3', recovery_key: NEW_KEY },
      'password-reset/cancel.php': {},
      'mfa/login-recovery.php': { user: viewer, csrf_token: 'fixture-4', recovery_key: NEW_KEY },
      'account/recovery-key.php': { recovery_key: NEW_KEY, created_at: '2026-11-08T11:00:00Z' },
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

async function fillReset(page, { username = 'Jamie', key = 'k7qf 3mzd-9xhv 2trp w8bn 6cja', password = 'a brand new passphrase' } = {}) {
  await page.getByRole('textbox', { name: 'Username' }).fill(username);
  await page.getByRole('textbox', { name: 'Recovery key' }).fill(key);
  await page.locator('#reset-password').fill(password);
  await page.getByRole('button', { name: 'Set new password' }).click();
}

for (const theme of ['dark', 'light']) {
  test(`${theme}: registration shows the recovery key once, before the chat`, async ({ page, context }, testInfo) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await page.emulateMedia({ colorScheme: theme, reducedMotion: 'reduce' });
    await page.setViewportSize({ width: 1440, height: 1000 });
    await fixture(page);
    await page.goto('/?register=1');
    await page.locator('#register-username').fill('Jamie');
    await page.locator('#register-password').fill('a long enough password');
    await page.getByRole('button', { name: 'Create account' }).click();

    const step = page.getByRole('region', { name: 'Save your recovery key' });
    await expect(step).toBeVisible();
    await expect(step.getByRole('heading', { name: 'Save your recovery key' })).toBeFocused();
    await expect(step.locator('.recovery-key')).toHaveText('K7QF3MZD9XHV2TRPW8BN6CJA');
    await expect(step.locator('.recovery-key span').first()).toHaveAttribute('aria-label', 'K 7 Q F');
    await expect(page.locator('#chat-shell')).toBeHidden();
    const proceed = step.getByRole('button', { name: /Continue to/ });
    await expect(proceed).toBeDisabled();
    await accessible(page);
    await page.screenshot({ path: testInfo.outputPath(`recovery-key-${theme}.png`), fullPage: true });

    await step.getByRole('button', { name: 'Copy' }).click();
    await expect(step.locator('.recovery-key-status')).toHaveText('Copied.');
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe('K7QF-3MZD-9XHV-2TRP-W8BN-6CJA');
    const download = page.waitForEvent('download');
    await step.getByRole('button', { name: 'Download as text file' }).click();
    expect((await download).suggestedFilename()).toBe('chitchat-recovery-key.txt');

    await page.setViewportSize({ width: 320, height: 740 });
    await noOverflow(page);
    await step.getByRole('checkbox', { name: 'I’ve saved my recovery key' }).check();
    await proceed.click();
    await expect(page.locator('#chat-shell')).toBeVisible();
  });
}

test('registration without a key (provider sign-up) goes straight in', async ({ page }) => {
  await fixture(page, { overrides: { 'register.php': { user: { ...viewer, recovery_key: null }, csrf_token: 'x' } } });
  await page.goto('/?register=1');
  await page.locator('#register-username').fill('Jamie');
  await page.locator('#register-password').fill('a long enough password');
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
  await expect(page.locator('#recovery-key-slot')).toBeHidden();
});

test('forgot password: generic failure, rate limit, then a new key', async ({ page }, testInfo) => {
  let attempts = 0;
  const requests = [];
  await fixture(page, {
    requests,
    overrides: {
      'password-reset.php': () => {
        attempts += 1;
        if (attempts === 1) return { status: 400, json: { error: { code: 'reset_failed', message: 'Reset failed.' } } };
        if (attempts === 2) return { status: 429, json: { error: { code: 'rate_limited', message: 'Too many requests.', retry_after_seconds: 240 } } };
        return { status: 'complete', user: viewer, csrf_token: 'x', recovery_key: NEW_KEY };
      },
    },
  });
  await page.goto('/');
  await page.getByRole('link', { name: 'Forgot password?' }).click();
  const reset = page.getByRole('region', { name: 'Set a new password' });
  await expect(reset).toBeVisible();
  await expect(page.getByRole('textbox', { name: 'Username' })).toBeFocused();
  await expect(page.locator('#login-form')).toBeHidden();
  await accessible(page);
  await page.screenshot({ path: testInfo.outputPath('reset-form.png'), fullPage: true });

  const toggle = page.getByRole('button', { name: 'Show password' });
  await toggle.click();
  await expect(page.getByRole('button', { name: 'Hide password' })).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('#reset-password')).toHaveAttribute('type', 'text');

  await fillReset(page);
  await expect(page.locator('#password-reset-status')).toHaveText('That username and recovery key don’t match. Check both and try again.');
  await fillReset(page);
  await expect(page.locator('#password-reset-status')).toHaveText('Too many attempts. Try again in 4 minutes.');
  await fillReset(page);
  expect(requests.filter((entry) => entry.path === 'password-reset.php').at(-1).body).toMatchObject({
    username: 'Jamie', recovery_key: 'k7qf 3mzd-9xhv 2trp w8bn 6cja', new_password: 'a brand new passphrase',
  });

  const step = page.getByRole('region', { name: 'Save your new recovery key' });
  await expect(step).toContainText('Password changed');
  await expect(step).toContainText('Your old key is used up. You were signed out on all other devices.');
  await expect(step.locator('.recovery-key')).toHaveText('Q4MN8RTX2VBC7HJK5PWD9FGS');
  await accessible(page);
  await page.screenshot({ path: testInfo.outputPath('reset-new-key.png'), fullPage: true });
});

test('an account with a second factor confirms it before anything changes, or cancels', async ({ page }) => {
  const requests = [];
  await fixture(page, { requests, overrides: { 'password-reset.php': { status: 'pending_mfa', methods: ['passkey', 'recovery_code'] } } });
  await page.goto('/?reset=1');
  await expect(page.getByRole('region', { name: 'Set a new password' })).toBeVisible();
  await fillReset(page);

  const panel = page.locator('#mfa-login-panel');
  await expect(panel.getByRole('heading', { name: 'One more step' })).toBeVisible();
  await expect(panel).toContainText('Your password changes after this.');
  await panel.getByRole('button', { name: 'Start over' }).click();
  expect(requests.some((entry) => entry.path === 'password-reset/cancel.php')).toBe(true);
  expect(requests.some((entry) => entry.path === 'logout.php')).toBe(false);
  await expect(page.getByRole('region', { name: 'Set a new password' })).toBeVisible();
  await expect(page.locator('#password-reset-status')).toHaveText('Nothing was changed.');

  await fillReset(page);
  await page.getByRole('textbox', { name: 'Recovery code' }).fill('ABCD-EF01-2345-6789-ABCD-EF01');
  await page.getByRole('button', { name: 'Use recovery code' }).click();
  await expect(page.getByRole('region', { name: 'Save your new recovery key' })).toBeVisible();
  await expect(panel).toBeHidden();
});

test('account: create a key, replace it, or nothing to recover', async ({ page }, testInfo) => {
  const requests = [];
  await fixture(page, { user: { ...viewer, recovery_key: keyless }, requests });
  await page.goto('/account.php');
  const row = page.locator('#recovery-row');
  await expect(row).toContainText('No recovery key. If you forget your password, only an administrator can reset it.');
  await row.getByRole('button', { name: 'Create recovery key' }).click();
  const dialog = page.getByRole('dialog', { name: 'Save your recovery key' });
  await expect(dialog).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(dialog).toBeVisible();
  await accessible(page);
  await page.screenshot({ path: testInfo.outputPath('account-recovery-dialog.png') });
  await dialog.getByRole('checkbox', { name: 'I’ve saved my recovery key' }).check();
  await dialog.getByRole('button', { name: 'Done' }).click();
  await expect(dialog).toBeHidden();
  await expect(row).toContainText('Recovery key · created');
  await expect(row.getByRole('button', { name: 'Create a new key' })).toBeFocused();

  await row.getByRole('button', { name: 'Create a new key' }).click();
  const confirm = page.getByRole('dialog', { name: 'Create a new recovery key?' });
  await expect(confirm).toContainText('Your current key stops working right away.');
  await confirm.getByRole('button', { name: 'Create new key' }).click();
  await expect(page.getByRole('dialog', { name: 'Save your recovery key' })).toBeVisible();
  expect(requests.filter((entry) => entry.path === 'account/recovery-key.php')).toHaveLength(2);

  await page.unroute('**/api/v1/**');
  await fixture(page, { user: { ...viewer, recovery_key: null } });
  await page.goto('/account.php');
  await expect(row).toContainText('There’s no password to forget.');
  await expect(row.getByRole('button')).toHaveCount(0);
});

test('the chat nudges password accounts without a key, once per device', async ({ page }) => {
  await fixture(page, { user: { ...viewer, recovery_key: keyless } });
  await page.goto('/');
  const nudge = page.locator('#recovery-nudge');
  await expect(nudge).toBeVisible();
  await expect(nudge.getByRole('link', { name: 'Create key' })).toHaveAttribute('href', '/account.php?recovery=create');
  await accessible(page);
  await nudge.getByRole('button', { name: 'Dismiss' }).click();
  await expect(nudge).toBeHidden();
  await page.reload();
  await expect(page.locator('#chat-shell')).toBeVisible();
  await expect(nudge).toBeHidden();

  await page.unroute('**/api/v1/**');
  await fixture(page, { user: viewer });
  await page.evaluate(() => localStorage.clear());
  await page.goto('/');
  await expect(page.locator('#chat-shell')).toBeVisible();
  await expect(nudge).toBeHidden();
});

test('a cancel the server never acknowledged, and a final step whose answer is lost, are said honestly', async ({ page }) => {
  await fixture(page, { overrides: { 'password-reset.php': { status: 'pending_mfa', methods: ['recovery_code'] } } });
  await page.route('**/api/v1/password-reset/cancel.php', (route) => route.abort('failed'));
  await page.goto('/?reset=1');
  await fillReset(page);
  await page.locator('#mfa-login-panel').getByRole('button', { name: 'Start over' }).click();
  await expect(page.locator('#password-reset-status')).toHaveText(/couldn’t reach the server to cancel/);
  await expect(page.locator('#password-reset-status')).not.toHaveText(/Nothing was changed/);

  await page.route('**/api/v1/mfa/login-recovery.php', (route) => route.abort('failed'));
  await fillReset(page);
  await page.getByRole('textbox', { name: 'Recovery code' }).fill('ABCD-EF01-2345-6789-ABCD-EF01');
  await page.getByRole('button', { name: 'Use recovery code' }).click();
  await expect(page.locator('#auth-error')).toHaveText(/couldn’t tell whether your password changed/);
  await expect(page.locator('#login-form')).toBeVisible();
  await expect(page.locator('#login-username')).toBeFocused();
});
