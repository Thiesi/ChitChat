import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// #204 on the web: authenticator apps. Setup on You (QR drawn on the page,
// the key as text, confirm, cancel, replace, remove, first recovery codes
// once), codes at sign-in, restoration, password reset and the step-up
// dialog, and the Super-Administrator key card. Presentation only: real
// pages and modules against intercepted API responses.
const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
const URI = `otpauth://totp/ChitChat:Jamie?secret=${SECRET}&issuer=ChitChat&algorithm=SHA1&digits=6&period=30`;
const NEW_KEY = 'Q4MN8RTX2VBC7HJK5PWD9FGS';
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const member = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1, recovery_key: { exists: true, created_at: '2026-11-01T10:00:00Z' } };
const totpOff = { enabled: false, available: true, created_at: null, last_used_at: null };
const mfaOff = { enabled: false, available: true, credentials: [], recovery_codes_remaining: 0, usable_methods: [], totp: totpOff };
const mfaApp = {
  enabled: true, available: true, credentials: [], recovery_codes_remaining: 10, usable_methods: ['totp', 'recovery_code'],
  totp: { enabled: true, available: true, created_at: '2026-11-09T10:00:00Z', last_used_at: '2026-11-09T12:00:00Z' },
};
const mfaAppUnavailable = { ...mfaApp, usable_methods: ['recovery_code'], totp: { ...mfaApp.totp, available: false } };
const adminKey = (overrides = {}) => ({ totp: {
  mode: 'unavailable', available: false, database_key_enabled: false, database_key_switch_available: true, database_key_references: 0,
  warning: 'Backups then contain both the key and every authenticator secret.', ...overrides,
} });

const error = (status, code, message) => ({ status, json: { error: { code, message } } });

async function fixture(page, { user = member, mfa = mfaOff, capability = true, overrides = {} } = {}) {
  const state = { calls: [], urls: [], signedIn: Boolean(user), mfa, stepUp: false };
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname.replace('/api/v1/', '');
    state.urls.push(request.url());
    if (request.method() === 'POST') state.calls.push({ path, body: request.postDataJSON?.() ?? null });
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path in overrides) {
      const value = await (typeof overrides[path] === 'function' ? overrides[path](request, state) : overrides[path]);
      if (Number.isInteger(value?.status)) return route.fulfill({ status: value.status, json: value.json });
      return route.fulfill({ json: value });
    }
    const responses = {
      'session.php': {
        user: state.signedIn ? user : null, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [],
        capabilities: capability ? { totp: true } : {}, web_push: { enabled: false },
      },
      'account/mfa/status.php': { mfa: state.mfa },
      'account/identities/list.php': { providers: [], identities: [], has_password: true },
      'account/mfa/totp/begin.php': { enrollment_id: 'enr-1', secret: SECRET, otpauth_uri: URI, expires_at: new Date(Date.now() + 300_000).toISOString(), algorithm: 'SHA1', digits: 6, period_seconds: 30 },
      'account/mfa/totp/confirm.php': { mfa: mfaApp, recovery_codes: [] },
      'account/mfa/totp/cancel.php': { cancelled: true },
      'account/mfa/totp/remove.php': { removed: true },
      'mfa/step-up-totp.php': { security: { privileged_step_up: { active: true, method: 'totp' } } },
      'admin/settings/get.php': { settings: { registration_enabled: true, guest_access_enabled: false, mfa_required_for_admin_roles: false, audit_retention_days: 365, deleted_attachment_retention_days: 30, deleted_room_grace_days: 30, direct_message_retention_days: 0, login_attempt_retention_days: 30, orphan_attachment_grace_hours: 24, realtime_event_retention_hours: 24, room_message_retention_days: 0, updated_at: '2026-11-01T10:00:00Z' } },
      'admin/settings/lockdown/get.php': { lockdown: { enabled: false, custom_message: null, since: null } },
      'admin/settings/application-name/get.php': { application_name: { default: 'ChitChat', override: null } },
      'admin/settings/registration-protection/get.php': { registration_protection: { overrides: { rate_limit_max_attempts: null, rate_limit_window_seconds: null, min_fill_seconds: null, proof_of_work_bits: null }, defaults: { rate_limit_max_attempts: 5, rate_limit_window_seconds: 3600, min_fill_seconds: 3, proof_of_work_bits: 16 }, effective: { rate_limit_max_attempts: 5, rate_limit_window_seconds: 3600, min_fill_seconds: 3, proof_of_work_bits: 16 } } },
      'password-reset/challenge.php': { challenge: null },
      'registration-challenge.php': { challenge: null },
      'rooms/list.php': { rooms: [] },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [] },
      'push/preferences.php': { preferences: { mentioned_push_enabled: true, pinged_push_enabled: true, quiet_hours: null }, devices: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return state;
}

const block = (page) => page.locator('.mfa-totp');
const axe = async (page, include, label) => {
  // Measure resting states: a pointer left over a button would test its hover colour (tracked separately).
  await page.mouse.move(0, 0);
  const result = await new AxeBuilder({ page }).include(include).withTags(WCAG).analyze();
  expect(result.violations.map(({ id, nodes }) => ({ id, nodes: nodes.map(({ target, failureSummary }) => ({ target, failureSummary })) })), label).toEqual([]);
};

// ---- You: setup and management ----

test('offered: You invites setting up an authenticator app', async ({ page }) => {
  await fixture(page);
  await page.goto('/account.php');
  await expect(block(page).getByRole('heading', { name: 'Authenticator app' })).toBeVisible();
  await expect(block(page).getByRole('button', { name: 'Set up an authenticator app' })).toBeVisible();
  await expect(page.locator('#mfa-summary')).toHaveText('Add a passkey or an authenticator app to require a second step after your password.');
});

test('not offered by the server and not set up: no authenticator block', async ({ page }) => {
  await fixture(page, { capability: false });
  await page.goto('/account.php');
  await expect(page.locator('#mfa-summary')).toBeVisible();
  await expect(block(page)).toBeHidden();
});

test('setup draws the QR on the page, shows the key as text, and never puts the secret in a URL or attribute', async ({ page }) => {
  const state = await fixture(page);
  await page.goto('/account.php');
  await block(page).getByRole('button', { name: 'Set up an authenticator app' }).click();
  const qr = block(page).getByRole('img', { name: 'QR code for your authenticator app' });
  await expect(qr).toBeVisible();
  expect(await qr.evaluate((node) => node.querySelector('path').getAttribute('d').length)).toBeGreaterThan(500);
  await expect(block(page).locator('.totp-key-value')).toHaveText('JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP');
  await expect(block(page).getByLabel('Code from the app')).toBeFocused();
  await expect(block(page).getByLabel('Code from the app')).toHaveAttribute('autocomplete', 'one-time-code');
  await expect(block(page).getByLabel('Code from the app')).toHaveAttribute('inputmode', 'numeric');
  const inAttributes = await page.evaluate((secret) => [...document.querySelectorAll('*')]
    .some((node) => [...node.attributes].some((attribute) => attribute.value.includes(secret))), SECRET);
  expect(inAttributes).toBe(false);
  expect(state.urls.some((url) => url.includes(SECRET))).toBe(false);
});

test('a wrong code says to wait for a new one; the right one finishes setup and shows first recovery codes once', async ({ page }) => {
  let attempts = 0;
  const state = await fixture(page, { overrides: {
    'account/mfa/totp/confirm.php': () => (++attempts === 1
      ? error(403, 'invalid_totp', 'Invalid code.')
      : { mfa: mfaApp, recovery_codes: ['AAAA-BBBB', 'CCCC-DDDD'] }),
  } });
  await page.goto('/account.php');
  await block(page).getByRole('button', { name: 'Set up an authenticator app' }).click();
  await block(page).getByLabel('Code from the app').fill('123 456');
  await block(page).getByRole('button', { name: 'Confirm' }).click();
  await expect(block(page).locator('.error-text')).toHaveText('That code didn’t work. Wait for a new code in your app, then enter it.');
  await block(page).getByLabel('Code from the app').fill('654321');
  await block(page).getByRole('button', { name: 'Confirm' }).click();
  expect(state.calls.filter((call) => call.path === 'account/mfa/totp/confirm.php').at(-1).body).toEqual({ enrollment_id: 'enr-1', code: '654321' });
  await expect(page.getByRole('heading', { name: 'Save your recovery codes now' })).toBeVisible();
  await page.getByRole('button', { name: 'I saved them' }).click();
  await expect(block(page)).toContainText('Set up');
  await expect(block(page).getByRole('button', { name: 'Remove authenticator app' })).toBeVisible();
  // The setup is gone from the page once it's confirmed.
  await expect(block(page).locator('.totp-qr')).toHaveCount(0);
  await expect(page.locator('#mfa-summary')).toContainText('a code from your authenticator app');
});

test('cancelling clears the setup from the page; an expired setup says to start again', async ({ page }) => {
  const state = await fixture(page, { overrides: { 'account/mfa/totp/confirm.php': error(409, 'totp_enrollment_expired', 'Expired.') } });
  await page.goto('/account.php');
  await block(page).getByRole('button', { name: 'Set up an authenticator app' }).click();
  await block(page).getByRole('button', { name: 'Cancel' }).click();
  await expect(block(page).locator('.totp-key-value')).toHaveCount(0);
  await expect(block(page)).toContainText('Setup cancelled.');
  expect(state.calls.find((call) => call.path === 'account/mfa/totp/cancel.php')?.body).toEqual({ enrollment_id: 'enr-1' });

  await block(page).getByRole('button', { name: 'Set up an authenticator app' }).click();
  await block(page).getByLabel('Code from the app').fill('111111');
  await block(page).getByRole('button', { name: 'Confirm' }).click();
  await expect(block(page).locator('.error-text')).toHaveText('The setup ended before it was confirmed. Start again to get a new key.');
  await expect(block(page).locator('.totp-key-value')).toHaveCount(0);
});

// The local five-minute timer must never swallow a confirm the server accepted.
test('a confirm answered after the local expiry still shows success and the first recovery codes', async ({ page }) => {
  await fixture(page, { overrides: {
    'account/mfa/totp/begin.php': () => ({ enrollment_id: 'enr-9', secret: SECRET, otpauth_uri: URI, expires_at: new Date(Date.now() + 800).toISOString() }),
    'account/mfa/totp/confirm.php': () => new Promise((resolve) => setTimeout(() => resolve({ mfa: mfaApp, recovery_codes: ['AAAA-BBBB'] }), 2000)),
  } });
  await page.goto('/account.php');
  await block(page).getByRole('button', { name: 'Set up an authenticator app' }).click();
  await block(page).getByLabel('Code from the app').fill('246802');
  await block(page).getByRole('button', { name: 'Confirm' }).click();
  await expect(page.getByRole('heading', { name: 'Save your recovery codes now' })).toBeVisible();
  await page.getByRole('button', { name: 'I saved them' }).click();
  await expect(block(page).getByRole('button', { name: 'Remove authenticator app' })).toBeVisible();
  await expect(block(page).locator('.error-text')).toHaveText('');
});

test('a step-up finished after the local expiry still confirms the setup it started', async ({ page }) => {
  let confirms = 0;
  const state = await fixture(page, { mfa: mfaApp, overrides: {
    'account/mfa/totp/begin.php': () => ({ enrollment_id: 'enr-7', secret: SECRET, otpauth_uri: URI, expires_at: new Date(Date.now() + 800).toISOString() }),
    'account/mfa/totp/confirm.php': () => (++confirms === 1 ? error(403, 'step_up_required', 'Confirm it’s you.') : { mfa: mfaApp, recovery_codes: [] }),
  } });
  await page.goto('/account.php');
  await block(page).getByRole('button', { name: 'Replace with a new app' }).click();
  await block(page).getByLabel('Code from the app').fill('112233');
  await block(page).getByRole('button', { name: 'Confirm' }).click();
  const dialog = page.getByRole('dialog', { name: 'Confirm with multi-factor authentication' });
  await expect(dialog).toBeVisible();
  await page.waitForTimeout(1200);
  await dialog.getByLabel('Code from your authenticator app').fill('445566');
  await dialog.getByRole('button', { name: 'Use code' }).click();
  await expect(block(page)).toContainText('Your new authenticator app is set up.');
  expect(state.calls.filter((call) => call.path === 'account/mfa/totp/confirm.php').at(-1).body).toEqual({ enrollment_id: 'enr-7', code: '112233' });
});

test('set up: replace confirms with the app in the step-up dialog first; remove explains the last second step', async ({ page }) => {
  let begins = 0;
  const state = await fixture(page, { mfa: mfaApp, overrides: {
    'account/mfa/totp/begin.php': () => (++begins === 1
      ? error(403, 'step_up_required', 'Confirm it’s you.')
      : { enrollment_id: 'enr-2', secret: SECRET, otpauth_uri: URI, expires_at: new Date(Date.now() + 300_000).toISOString() }),
    'account/mfa/totp/remove.php': error(409, 'last_mfa_method', 'Last method.'),
  } });
  await page.goto('/account.php');
  await expect(block(page)).toContainText('last used');
  await block(page).getByRole('button', { name: 'Replace with a new app' }).click();
  const dialog = page.getByRole('dialog', { name: 'Confirm with multi-factor authentication' });
  await expect(dialog.getByLabel('Code from your authenticator app')).toBeFocused();
  await axe(page, '.step-up-dialog', 'step-up dialog with the app');
  await dialog.getByLabel('Code from your authenticator app').fill('222333');
  await dialog.getByRole('button', { name: 'Use code' }).click();
  expect(state.calls.find((call) => call.path === 'mfa/step-up-totp.php')?.body).toEqual({ code: '222333' });
  await expect(block(page)).toContainText('Your current app keeps working until you confirm.');
  await block(page).getByRole('button', { name: 'Cancel' }).click();

  page.once('dialog', (confirm) => confirm.accept());
  await block(page).getByRole('button', { name: 'Remove authenticator app' }).click();
  await expect(block(page).locator('.error-text')).toHaveText('This is your only second step. Add a passkey first, or turn off two-step sign-in below.');
});

test('set up but unavailable on the server: You says so and offers the other ways', async ({ page }) => {
  await fixture(page, { mfa: mfaAppUnavailable });
  await page.goto('/account.php');
  await expect(block(page)).toContainText('can’t be checked on this server right now');
  await expect(block(page).getByRole('button')).toHaveCount(0);
});

// ---- Entry: sign-in, restoration, password reset ----

test('sign-in offers the app first when the account has one, and finishes with a code', async ({ page }) => {
  const state = await fixture(page, { user: null, overrides: {
    'login.php': { mfa_required: true, methods: ['passkey', 'recovery_code', 'totp'], csrf_token: 'pending' },
    'mfa/login-totp.php': (request, current) => {
      current.signedIn = true;
      return { user: member, csrf_token: 'fixture', mfa_method: 'totp' };
    },
  } });
  await page.goto('/');
  await page.locator('#login-username').fill('Jamie');
  await page.locator('#login-password').fill('a long passphrase');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  const panel = page.locator('#mfa-login-panel');
  await expect(panel.getByLabel('Code from your authenticator app')).toBeFocused();
  await expect(panel).toContainText('Enter the code from your authenticator app, or use a passkey.');
  await axe(page, '#mfa-login-panel', 'sign-in with the app');
  await panel.getByLabel('Code from your authenticator app').fill('123-456');
  await panel.getByRole('button', { name: 'Use code' }).click();
  await expect.poll(() => state.calls.find((call) => call.path === 'mfa/login-totp.php')?.body).toEqual({ code: '123-456' });
});

test('sign-in without an app on the account shows no code field; a wrong code waits for a new one', async ({ page }) => {
  await fixture(page, { user: null, overrides: {
    'login.php': { mfa_required: true, methods: ['passkey', 'recovery_code'], csrf_token: 'pending' },
  } });
  await page.goto('/');
  await page.locator('#login-username').fill('Jamie');
  await page.locator('#login-password').fill('a long passphrase');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.locator('#mfa-login-panel')).toBeVisible();
  await expect(page.locator('#mfa-login-panel').getByLabel('Code from your authenticator app')).toBeHidden();
});

test('a wrong sign-in code says to wait for a new one', async ({ page }) => {
  await fixture(page, { user: null, overrides: {
    'login.php': { mfa_required: true, methods: ['passkey', 'recovery_code', 'totp'], csrf_token: 'pending' },
    'mfa/login-totp.php': error(403, 'invalid_totp', 'Invalid code.'),
  } });
  await page.goto('/');
  await page.locator('#login-username').fill('Jamie');
  await page.locator('#login-password').fill('a long passphrase');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  const panel = page.locator('#mfa-login-panel');
  await panel.getByLabel('Code from your authenticator app').fill('000000');
  await panel.getByRole('button', { name: 'Use code' }).click();
  await expect(panel.locator('.error-text')).toHaveText('That code didn’t work. Wait for a new code in your app, then enter it.');
  await axe(page, '#mfa-login-panel', 'sign-in error');
});

test('a Google or Twitch sign-in that continues here uses the session’s pending methods', async ({ page }) => {
  let pending = { methods: ['passkey', 'recovery_code', 'totp'] };
  await fixture(page, { user: null, overrides: {
    'session.php': () => ({ user: null, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [], capabilities: { totp: true }, mfa_pending: pending }),
  } });
  await page.goto('/?mfa=continue');
  await expect(page.locator('#mfa-login-panel').getByLabel('Code from your authenticator app')).toBeVisible();
  // No valid pending step (null): passkey and recovery code only, even though the server supports apps.
  pending = null;
  await page.goto('/?mfa=continue');
  await expect(page.locator('#mfa-login-panel')).toBeVisible();
  await expect(page.locator('#mfa-login-panel').getByLabel('Code from your authenticator app')).toBeHidden();
});

test('restoring an account accepts a code from the app', async ({ page }) => {
  const state = await fixture(page, { user: null, overrides: {
    'account/restore.php': { mfa_required: true, methods: ['passkey', 'recovery_code', 'totp'], csrf_token: 'pending' },
    'mfa/login-totp.php': { user: member, csrf_token: 'fixture', restored: true },
  } });
  await page.goto('/restore-account.php');
  await page.locator('#restore-username').fill('Jamie');
  await page.locator('#restore-password').fill('a long passphrase');
  await page.locator('#restore-account-form').getByRole('button').first().click();
  const panel = page.locator('#restore-mfa-panel');
  await expect(panel.getByLabel('Code from your authenticator app')).toBeFocused();
  await axe(page, '#restore-mfa-panel', 'restoration with the app');
  await panel.getByLabel('Code from your authenticator app').fill('987654');
  await panel.getByRole('button', { name: 'Use code' }).click();
  await expect.poll(() => state.calls.find((call) => call.path === 'mfa/login-totp.php')?.body).toEqual({ code: '987654' });
});

test('a password reset confirms with a code from the app before the password changes', async ({ page }) => {
  const state = await fixture(page, { user: null, overrides: {
    'password-reset.php': { status: 'pending_mfa', methods: ['passkey', 'recovery_code', 'totp'] },
    'password-reset/mfa-totp.php': { user: member, csrf_token: 'fixture-4', recovery_key: NEW_KEY },
  } });
  await page.goto('/?reset=1');
  await page.getByRole('textbox', { name: 'Username' }).fill('Jamie');
  await page.getByRole('textbox', { name: 'Recovery key' }).fill('k7qf 3mzd-9xhv 2trp w8bn 6cja');
  await page.locator('#reset-password').fill('a brand new passphrase');
  await page.getByRole('button', { name: 'Set new password' }).click();
  const panel = page.locator('#mfa-login-panel');
  await expect(panel).toContainText('a code from your authenticator app');
  await panel.getByLabel('Code from your authenticator app').fill('135 790');
  await panel.getByRole('button', { name: 'Use code' }).click();
  await expect.poll(() => state.calls.find((call) => call.path === 'password-reset/mfa-totp.php')?.body).toEqual({ code: '135 790' });
  await expect(page.getByRole('region', { name: 'Save your new recovery key' })).toBeVisible();
});

// ---- Super-Administrator key card ----

const superAdmin = { ...member, roles: ['super_admin'] };

test('the key card: the server key wins and locks the switch', async ({ page }) => {
  await fixture(page, { user: superAdmin, overrides: { 'admin/settings/totp/get.php': adminKey({ mode: 'server', available: true, database_key_switch_available: false }) } });
  await page.goto('/admin-settings.php');
  const card = page.locator('#totp-settings');
  await expect(card.getByRole('heading', { name: 'Authenticator apps' })).toBeVisible();
  await expect(card).toContainText('kept on the server (recommended)');
  await expect(card.getByRole('checkbox', { name: 'Store the authenticator key inside ChitChat' })).toBeDisabled();
  await expect(card).toContainText('which always takes precedence');
});

test('the key card: stored inside ChitChat with secrets in use cannot be turned off yet', async ({ page }) => {
  await fixture(page, { user: superAdmin, overrides: { 'admin/settings/totp/get.php': adminKey({ mode: 'database', available: true, database_key_enabled: true, database_key_references: 3 }) } });
  await page.goto('/admin-settings.php');
  const card = page.locator('#totp-settings');
  const toggle = card.getByRole('checkbox', { name: 'Store the authenticator key inside ChitChat' });
  await expect(toggle).toBeChecked();
  await expect(toggle).toBeDisabled();
  await expect(card).toContainText('3 authenticator secrets still use this key');
});

test('the key card: turning it on asks first with the backup warning, then saves', async ({ page }) => {
  let warned = '';
  const state = await fixture(page, { user: superAdmin, overrides: {
    'admin/settings/totp/get.php': adminKey(),
    'admin/settings/totp/update.php': adminKey({ mode: 'database', available: true, database_key_enabled: true }),
  } });
  await page.goto('/admin-settings.php');
  const card = page.locator('#totp-settings');
  await expect(card).toContainText('No key is set up');
  await expect(card.locator('#totp-warning')).toHaveText('Backups then contain both the key and every authenticator secret.');
  page.once('dialog', (dialog) => { warned = dialog.message(); dialog.accept(); });
  await card.getByRole('checkbox', { name: 'Store the authenticator key inside ChitChat' }).check();
  await expect(card.locator('#totp-status')).toHaveText('The key is now stored inside ChitChat.');
  expect(warned).toContain('Backups then contain both the key');
  expect(state.calls.find((call) => call.path === 'admin/settings/totp/update.php')?.body).toEqual({ database_key_enabled: true });
});

test('the key card: a refused turn-off is explained', async ({ page }) => {
  await fixture(page, { user: superAdmin, overrides: {
    'admin/settings/totp/get.php': adminKey({ mode: 'database', available: true, database_key_enabled: true, database_key_references: 0 }),
    'admin/settings/totp/update.php': error(409, 'totp_key_in_use', 'In use.'),
  } });
  await page.goto('/admin-settings.php');
  const card = page.locator('#totp-settings');
  // A refused change puts the switch back, so click rather than uncheck (which insists on the new state).
  const toggle = card.getByRole('checkbox', { name: 'Store the authenticator key inside ChitChat' });
  await toggle.click();
  await expect(toggle).toBeChecked();
  await expect(card.locator('#totp-error')).toHaveText('Authenticator secrets still use this key, so it can’t be turned off yet. Move them to a server key first.');
});

test('the key card stays away from anyone the server refuses', async ({ page }) => {
  await fixture(page, { user: { ...member, roles: ['admin'] }, overrides: { 'admin/settings/totp/get.php': error(403, 'forbidden', 'Forbidden.') } });
  await page.goto('/admin-settings.php');
  await page.waitForTimeout(500);
  await expect(page.locator('#totp-settings')).toBeHidden();
});

// ---- Every state in both themes ----

for (const theme of ['light', 'dark']) {
  test(`${theme}: setup states and the key card pass axe at 1280 and 360 px`, async ({ page }, testInfo) => {
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await fixture(page, { user: superAdmin, overrides: { 'admin/settings/totp/get.php': adminKey({ mode: 'database', available: true, database_key_enabled: true, database_key_references: 2 }) } });
    for (const width of [1280, 360]) {
      await page.setViewportSize({ width, height: 860 });
      await page.goto('/account.php');
      await expect(block(page).getByRole('button', { name: 'Set up an authenticator app' })).toBeVisible();
      await axe(page, '.mfa-totp', `offered ${width}`);
      await block(page).getByRole('button', { name: 'Set up an authenticator app' }).click();
      await expect(block(page).locator('.totp-qr')).toBeVisible();
      await axe(page, '.mfa-totp', `setup ${width}`);
      await block(page).screenshot({ path: testInfo.outputPath(`setup-${theme}-${width}.png`) });
      expect(await page.evaluate(() => document.documentElement.scrollWidth), `setup reflow ${width}`).toBeLessThanOrEqual(width);
      await page.goto('/admin-settings.php');
      await expect(page.locator('#totp-settings')).toBeVisible();
      await axe(page, '#totp-settings', `key card ${width}`);
      await page.locator('#totp-settings').screenshot({ path: testInfo.outputPath(`key-card-${theme}-${width}.png`) });
    }
  });

  test(`${theme}: set up and unavailable states pass axe`, async ({ page }) => {
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await fixture(page, { mfa: mfaApp });
    await page.goto('/account.php');
    await expect(block(page).getByRole('button', { name: 'Remove authenticator app' })).toBeVisible();
    await axe(page, '.mfa-totp', 'set up');
  });
}
