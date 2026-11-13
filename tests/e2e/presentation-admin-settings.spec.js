import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// Operational settings: the username reservation (#164; system-settings.md),
// operable inside ChitChat like every other setting. S6b (#172, spec comment
// 6842902529): the Dusk frame, one styled confirmation for a save with
// consequences, the lockdown sign-out question, System status, and the
// admin-settings baseline. Presentation only: the real page against
// intercepted APIs.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const admin = { id: 1, username: 'Root', roles: ['super_admin'], session_version: 1 };
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const settings = (extra = {}) => ({
  registration_enabled: true, guest_access_enabled: false, mfa_required_for_admin_roles: false,
  audit_retention_days: 0, deleted_attachment_retention_days: 0, deleted_room_grace_days: 30,
  direct_message_retention_days: 0, login_attempt_retention_days: 30, orphan_attachment_grace_hours: 24,
  realtime_event_retention_hours: 24, room_message_retention_days: 0, updated_at: '2026-11-01T10:00:00Z', ...extra,
});

const SYSTEM_STATUS = {
  generated_at: '2026-11-12T10:00:00Z',
  application: { name: 'ChitChat', version: '3.9.0', environment: 'production' },
  database: { name: 'chitchat', size_bytes: 52428800, query_latency_ms: 0.412 },
  realtime: { active_sse_connections: 4, active_sse_users: 3, active_presence_leases: 3, active_presence_users: 3, retained_events: 1200 },
  attachments: { active_files: 18, deleted_files: 2, tracked_bytes: 7340032, storage_available: true, disk_free_bytes: 10737418240, disk_total_bytes: 21474836480, disk_used_percent: 50 },
  security: {
    failed_logins_24h: 1, rate_limit_rows: 6,
    rate_limit_policies: { login: { maximum_attempts: 10, window_seconds: 900 }, register: { maximum_attempts: 5, window_seconds: 3600 } },
    rate_limit_decisions: [{ policy: 'login', allowed: 40, rejected: 1, last_rejected_at: '2026-11-12T09:00:00Z' }],
  },
  maintenance: {
    overdue: false, maximum_age_hours: 26, latest_success_age_seconds: 3600,
    latest_run: { status: 'succeeded', dry_run: false, finished_at: '2026-11-12T09:00:00Z', duration_ms: 812, result: null, error_message: null },
    latest_successful_destructive_run: { finished_at: '2026-11-12T09:00:00Z' },
  },
  metrics: { enabled: false },
};

async function fixture(page, { current = settings({ username_reservation_days: 365 }), status = SYSTEM_STATUS } = {}) {
  const requests = [];
  await page.clock.setFixedTime(new Date('2026-11-12T12:00:00Z'));
  await page.route('**/installation-icon.php*', (route) => route.fulfill({ contentType: 'image/png', body: PNG }));
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace('/api/v1/', '');
    if (request.method() === 'POST') requests.push({ path, body: request.postDataJSON?.() ?? null });
    // The authenticator key card has its own spec (presentation-totp); here it stays out of the way (it hides on 403).
    if (path === 'admin/settings/totp/get.php') return route.fulfill({ status: 403, json: { error: { code: 'forbidden', message: 'Forbidden.' } } });
    if (path === 'admin/settings/update.php') {
      const body = request.postDataJSON();
      return route.fulfill({ json: { settings: { ...current, ...body, updated_at: '2026-11-12T10:00:00Z' } } });
    }
    const responses = {
      'session.php': { user: admin, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'admin/settings/get.php': { settings: current },
      'admin/settings/lockdown/get.php': { lockdown: { enabled: false, custom_message: null, since: null } },
      'admin/settings/lockdown/update.php': { lockdown: { enabled: true, custom_message: null, since: '2026-11-12T10:00:00Z', signed_out: 3 } },
      'admin/system-status.php': { status },
      'admin/settings/application-name/get.php': { application_name: { default: 'ChitChat', override: null } },
      'admin/settings/registration-protection/get.php': { registration_protection: { overrides: { rate_limit_max_attempts: null, rate_limit_window_seconds: null, min_fill_seconds: null, proof_of_work_bits: null }, defaults: { rate_limit_max_attempts: 5, rate_limit_window_seconds: 3600, min_fill_seconds: 3, proof_of_work_bits: 16 }, effective: { rate_limit_max_attempts: 5, rate_limit_window_seconds: 3600, min_fill_seconds: 3, proof_of_work_bits: 16 } } },
      'admin/policies.php': { policies: { rules: { source: 'none', markdown: '', url: null, version: 0, updated_at: null }, privacy: { source: 'none', markdown: '', url: null, version: 0, updated_at: null }, legal_notice: { url: null } } },
      'admin/settings/sign-in-decoration/get.php': { sign_in_decoration: { value: 'off', options: ['off', 'winter', 'spring', 'celebration'] } },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return requests;
}

const field = (page) => page.getByRole('spinbutton', { name: /Username reservation in days/ });

async function open(page) {
  await page.goto('/admin-settings.php');
  await expect(page.locator('#settings-shell')).toBeVisible();
}

test('the reservation shows the server’s value with what it means, and saves with the policy', async ({ page }) => {
  const requests = await fixture(page);
  await open(page);
  await expect(field(page)).toHaveValue('365');
  await expect(field(page)).toHaveAccessibleName(/0 releases it at once\. It’s always held while a change is pending\./);
  await expect(field(page)).toHaveAttribute('min', '0');
  await expect(field(page)).toHaveAttribute('max', '3650');
  await field(page).fill('30');
  await page.getByRole('button', { name: 'Save operational settings' }).click();
  await expect.poll(() => requests.find((entry) => entry.path === 'admin/settings/update.php')?.body?.username_reservation_days).toBe(30);
  await expect(field(page)).toHaveValue('30');
});

test('0 releases a name at once and is saved as 0', async ({ page }) => {
  const requests = await fixture(page);
  await open(page);
  await field(page).fill('0');
  await page.getByRole('button', { name: 'Save operational settings' }).click();
  await expect.poll(() => requests.find((entry) => entry.path === 'admin/settings/update.php')?.body?.username_reservation_days).toBe(0);
});

test('out of range is refused by the form, and nothing is sent', async ({ page }) => {
  const requests = await fixture(page);
  await open(page);
  await field(page).fill('4000');
  await page.getByRole('button', { name: 'Save operational settings' }).click();
  expect(await field(page).evaluate((input) => input.validity.rangeOverflow)).toBe(true);
  expect(requests.some((entry) => entry.path === 'admin/settings/update.php')).toBe(false);
});

test('an older server without the setting: no field, and the save leaves it out', async ({ page }) => {
  const requests = await fixture(page, { current: settings() });
  await open(page);
  await expect(page.locator('#username-reservation-field')).toBeHidden();
  await page.getByRole('button', { name: 'Save operational settings' }).click();
  await expect.poll(() => requests.some((entry) => entry.path === 'admin/settings/update.php')).toBe(true);
  expect('username_reservation_days' in requests.find((entry) => entry.path === 'admin/settings/update.php').body).toBe(false);
});

for (const theme of ['light', 'dark']) {
  test(`${theme}: the retention card with the reservation passes axe at 1280 and 360px`, async ({ page }) => {
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await fixture(page);
    for (const width of [1280, 360]) {
      await page.setViewportSize({ width, height: 900 });
      await open(page);
      await field(page).focus();
      await page.mouse.move(0, 0);
      const result = await new AxeBuilder({ page }).include('#settings-form').withTags(WCAG).analyze();
      expect(result.violations, `${width}`).toEqual([]);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    }
  });
}

// ---- S6b: one question per save, only for what applies ----

const ask = (page) => page.getByRole('dialog', { name: 'Save operational settings?' });
const saves = (requests) => requests.filter((entry) => entry.path === 'admin/settings/update.php');

test('a save with no consequences asks nothing', async ({ page }) => {
  const requests = await fixture(page);
  await open(page);
  await page.getByRole('button', { name: 'Save operational settings' }).click();
  await expect.poll(() => saves(requests).length).toBe(1);
  await expect(ask(page)).toHaveCount(0);
});

test('each consequence that applies is its own line, in one styled question; Cancel saves nothing', async ({ page }) => {
  let native = 0;
  page.on('dialog', (dialog) => { native += 1; void dialog.dismiss(); });
  const requests = await fixture(page, { current: settings({ guest_access_enabled: true, username_reservation_days: 365 }) });
  await open(page);
  await page.locator('#guest-access-enabled').selectOption('0');
  await page.locator('#room-retention').fill('90');
  await page.locator('#admin-mfa-required').selectOption('1');
  await page.getByRole('button', { name: 'Save operational settings' }).click();
  await expect(ask(page).getByRole('listitem')).toHaveText([
    'Switching guest access off ends every guest visit at once.',
    'Nonzero retention values permanently delete older data when maintenance runs.',
    'Passkey-based MFA becomes required for every administrative role. Existing administrators must already be enrolled, and future role grants will be refused until the target account enrolls.',
  ]);
  await expect(ask(page).getByRole('button', { name: 'Cancel' })).toBeFocused();
  await page.mouse.move(0, 0);
  const result = await new AxeBuilder({ page }).include('.confirm-dialog').withTags(WCAG).analyze();
  expect(result.violations).toEqual([]);
  await page.keyboard.press('Escape');
  expect(saves(requests)).toEqual([]);

  await page.getByRole('button', { name: 'Save operational settings' }).click();
  await ask(page).getByRole('button', { name: 'Save settings' }).click();
  await expect.poll(() => saves(requests).length).toBe(1);
  expect(saves(requests)[0].body).toMatchObject({ guest_access_enabled: false, room_message_retention_days: 90, mfa_required_for_admin_roles: true });
  expect(native).toBe(0);
});

test('only what applies is listed: nonzero retention alone', async ({ page }) => {
  await fixture(page, { current: settings({ audit_retention_days: 365, username_reservation_days: 365 }) });
  await open(page);
  await page.getByRole('button', { name: 'Save operational settings' }).click();
  await expect(ask(page).getByRole('listitem')).toHaveText(['Nonzero retention values permanently delete older data when maintenance runs.']);
});

test('lockdown: signing everyone else out asks first; saving without it doesn’t', async ({ page }) => {
  const requests = await fixture(page);
  await open(page);
  await page.locator('#lockdown-enabled').selectOption('1');
  await page.locator('#lockdown-sign-out').check();
  await page.getByRole('button', { name: 'Save lockdown' }).click();
  const question = page.getByRole('dialog', { name: 'Sign out everyone except Super-Administrators?' });
  await expect(question).toContainText('can’t sign in again until lockdown ends');
  await page.keyboard.press('Escape');
  expect(requests.filter((entry) => entry.path === 'admin/settings/lockdown/update.php')).toEqual([]);
  await page.getByRole('button', { name: 'Save lockdown' }).click();
  await question.getByRole('button', { name: 'Sign everyone out' }).click();
  await expect.poll(() => requests.find((entry) => entry.path === 'admin/settings/lockdown/update.php')?.body).toEqual({ enabled: true, message: null, sign_out_others: true });
});

test('the frame: the save is a steward primary, not a danger button; the notice says it plainly', async ({ page }) => {
  await fixture(page);
  await open(page);
  await expect(page.getByRole('region', { name: 'These settings apply to everyone on this server' })).toContainText('A retention of 0 keeps data for good');
  const save = page.getByRole('button', { name: 'Save operational settings' });
  await expect(save).toHaveClass(/primary-button/);
  await expect.poll(() => save.evaluate((button) => {
    const probe = document.createElement('span');
    probe.style.color = 'var(--steward)';
    document.body.append(probe);
    const steward = getComputedStyle(probe).color;
    probe.remove();
    return getComputedStyle(button).backgroundColor === steward;
  })).toBe(true);
});

// ---- System status ----

test('System status: every card filled, the rate-limit table, and the plain note', async ({ page }) => {
  await fixture(page);
  await page.goto('/admin-status.php');
  await expect(page.locator('#status-generated')).toContainText('Measured');
  await expect(page.locator('#application-version')).toHaveText('3.9.0');
  await expect(page.locator('#maintenance-state')).toHaveText('Current');
  await expect(page.locator('#rate-limit-policies tr')).toHaveCount(2);
  await expect(page.getByText('The limits in effect on this server, and how often each one allowed or refused a request. No account or IP address is kept here.')).toBeVisible();
});

for (const [scheme, theme] of [['lantern', 'light'], ['lantern', 'dark'], ['dusk', 'dark']]) {
  test(`${scheme} ${theme}: Operational settings and System status pass axe at 1280 and 360, and reflow at 320`, async ({ page }) => {
    await page.addInitScript(([chosenScheme, chosenTheme]) => {
      localStorage.setItem('chitchat-theme', chosenTheme);
      localStorage.setItem('chitchat-scheme', chosenScheme);
    }, [scheme, theme]);
    // With the health checks and both new cards showing, in a neutral and a warning state.
    await fixture(page, {
      current: health({ maintenance_max_age_source: 'setting' }),
      status: withHealth(push({ configured: false, status: 'not_configured' }), backups({ status: 'failed', latest_run: run({ outcome: 'failure' }) })),
    });
    for (const path of ['/admin-settings.php', '/admin-status.php']) {
      for (const width of [1280, 360]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(path);
        await expect(page.locator('main')).toBeVisible();
        await page.waitForLoadState('networkidle');
        await page.mouse.move(0, 0);
        await page.waitForTimeout(250);
        const result = await new AxeBuilder({ page }).withTags(WCAG).analyze();
        expect(result.violations, `${path} ${width}: ${result.violations.map((violation) => `${violation.id} ${violation.nodes.map((node) => node.target).join(' ')}`).join('; ')}`).toEqual([]);
      }
      await page.setViewportSize({ width: 320, height: 700 });
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `${path} at 320`).toBe(true);
    }
  });
}

test('baseline: Operational settings at 1280 in Lantern dark', async ({ page, browserName }) => {
  test.skip(browserName !== 'chromium' || process.platform !== 'linux', 'Visual baselines are pinned Chromium on Linux (CI).');
  await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce' });
  await fixture(page);
  await page.setViewportSize({ width: 1280, height: 900 });
  await open(page);
  await expect(page.locator('#settings-updated')).toContainText('Last changed');
  await page.waitForLoadState('networkidle');
  await page.mouse.move(0, 0);
  await expect(page).toHaveScreenshot('admin-settings-desktop.png', { animations: 'disabled', caret: 'hide', fullPage: true, maxDiffPixelRatio: 0.003, threshold: 0.2 });
});

// ---- #310, #312: phone alerts, backups and the two maximum ages ----

const push = (extra = {}) => ({
  configured: true, status: 'ok', pending_deliveries: 2, due_deliveries: 0, failed_deliveries: 1,
  oldest_pending_seconds: 40, oldest_due_seconds: null, last_sent_at: '2026-11-12T11:58:00Z', overdue_after_seconds: 300, overdue: false, ...extra,
});
const run = (extra = {}) => ({ started_at: '2026-11-12T03:00:00Z', finished_at: '2026-11-12T03:02:00Z', outcome: 'success', consistency: 'live', size_bytes: 52428800, set_name: 'set-1', error_kind: null, ...extra });
const backups = (extra = {}) => ({
  tracking_enabled: true, maximum_age_hours: 26, status: 'ok', latest_run: run(), latest_success: run(), latest_success_age_seconds: 32400, overdue: false, ...extra,
});
const withHealth = (pushValue, backupsValue, maintenance = {}) => ({
  ...SYSTEM_STATUS, push: pushValue, backups: backupsValue, maintenance: { ...SYSTEM_STATUS.maintenance, maximum_age_source: 'environment', ...maintenance },
});

async function openStatus(page, status) {
  await fixture(page, { status });
  await page.goto('/admin-status.php');
  await expect(page.locator('#status-generated')).toContainText('Measured');
}

test('System status: phone alerts and backups when all is well, and where the maintenance age comes from', async ({ page }) => {
  await openStatus(page, withHealth(push(), backups()));
  await expect(page.locator('#push-state')).toHaveText('Current');
  await expect(page.locator('#push-pending')).toHaveText('2');
  await expect(page.locator('#push-failed')).toHaveText('1');
  await expect(page.locator('#push-note')).toBeHidden();
  await expect(page.locator('#backups-state')).toHaveText('Current');
  await expect(page.locator('#backups-latest')).toContainText('Succeeded');
  await expect(page.locator('#backups-success')).toContainText('9h ago');
  await expect(page.locator('#backups-max-age')).toHaveText('26 hours');
  await expect(page.locator('#maintenance-max-age')).toHaveText('26 hours (server default)');
});

test('System status: push not set up is neutral, with no queue figures', async ({ page }) => {
  await openStatus(page, withHealth(push({ configured: false, status: 'not_configured' }), backups()));
  await expect(page.locator('#push-state')).toHaveText('Not set up');
  await expect(page.locator('#push-state')).toHaveClass(/neutral/);
  await expect(page.locator('#push-card')).not.toHaveClass(/status-warning-card/);
  await expect(page.locator('#push-note')).toContainText('That’s fine if you don’t use them.');
  await expect(page.locator('#push-facts')).toBeHidden();
});

test('System status: an overdue push says the sender isn’t running', async ({ page }) => {
  await openStatus(page, withHealth(push({ status: 'overdue', overdue: true, due_deliveries: 3, oldest_due_seconds: 420 }), backups()));
  await expect(page.locator('#push-state')).toHaveText('Overdue');
  await expect(page.locator('#push-card')).toHaveClass(/status-warning-card/);
  await expect(page.locator('#push-note')).toHaveText('An alert that is due has waited more than 5 minutes, so the alert sender isn’t running.');
  await expect(page.locator('#push-due')).toHaveText('3 (oldest waiting 7m)');
});

for (const [state, label, note] of [
  ['unknown', 'Unknown', 'No backup has been recorded.'],
  ['failed', 'Failed', 'The latest backup run failed.'],
  ['overdue', 'Overdue', 'The newest successful backup is older than 26 hours.'],
]) {
  test(`System status: backups ${state} is a warning that says why`, async ({ page }) => {
    const latest = state === 'unknown' ? null : run({ outcome: state === 'failed' ? 'failure' : 'success' });
    await openStatus(page, withHealth(push(), backups({ status: state, overdue: state !== 'failed', latest_run: latest, latest_success: state === 'unknown' ? null : run(), latest_success_age_seconds: state === 'unknown' ? null : 100000 })));
    await expect(page.locator('#backups-state')).toHaveText(label);
    await expect(page.locator('#backups-card')).toHaveClass(/status-warning-card/);
    await expect(page.locator('#backups-note')).toContainText(note);
  });
}

test('System status: backup tracking off is neutral; an older server shows neither card', async ({ page }) => {
  await openStatus(page, withHealth(push(), backups({ tracking_enabled: false, maximum_age_hours: 0, status: 'not_tracked' }), { maximum_age_source: 'setting' }));
  await expect(page.locator('#backups-state')).toHaveText('Not tracked');
  await expect(page.locator('#backups-max-age')).toHaveText('Off');
  await expect(page.locator('#maintenance-max-age')).toHaveText('26 hours (set in Operational settings)');
  await page.unroute('**/api/v1/**');
  await openStatus(page, SYSTEM_STATUS);
  await expect(page.locator('#push-card')).toBeHidden();
  await expect(page.locator('#backups-card')).toBeHidden();
});

const health = (extra = {}) => settings({ username_reservation_days: 365, backup_max_age_hours: 26, maintenance_max_age_hours: 26, maintenance_max_age_source: 'environment', ...extra });
const lastSave = (requests) => requests.filter((entry) => entry.path === 'admin/settings/update.php').at(-1)?.body;

test('Health checks: backup age is saved; the maintenance age from the server default is left alone unless changed', async ({ page }) => {
  const requests = await fixture(page, { current: health() });
  await open(page);
  await expect(page.getByRole('heading', { name: 'Health checks' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Use the server default' })).toBeHidden();
  await page.getByLabel(/Backup maximum age, in hours/).fill('48');
  await page.getByRole('button', { name: 'Save operational settings' }).click();
  await expect.poll(() => lastSave(requests)?.backup_max_age_hours).toBe(48);
  expect('maintenance_max_age_hours' in lastSave(requests)).toBe(false);
});

test('Health checks: a changed maintenance age is sent; Use the server default sends null', async ({ page }) => {
  const requests = await fixture(page, { current: health({ maintenance_max_age_hours: 36, maintenance_max_age_source: 'setting' }) });
  await open(page);
  await page.getByLabel(/Maintenance maximum age, in hours/).fill('48');
  await page.getByRole('button', { name: 'Save operational settings' }).click();
  await expect.poll(() => lastSave(requests)?.maintenance_max_age_hours).toBe(48);
  await page.getByRole('button', { name: 'Use the server default' }).click();
  await page.getByRole('button', { name: 'Save operational settings' }).click();
  await expect.poll(() => requests.filter((entry) => entry.path === 'admin/settings/update.php').length).toBe(2);
  expect(lastSave(requests).maintenance_max_age_hours).toBeNull();
});

test('Health checks: an older server without them shows no card and sends neither', async ({ page }) => {
  const requests = await fixture(page);
  await open(page);
  await expect(page.locator('#health-checks')).toBeHidden();
  await page.getByRole('button', { name: 'Save operational settings' }).click();
  await expect.poll(() => lastSave(requests)).toBeTruthy();
  expect('backup_max_age_hours' in lastSave(requests)).toBe(false);
  expect('maintenance_max_age_hours' in lastSave(requests)).toBe(false);
});
