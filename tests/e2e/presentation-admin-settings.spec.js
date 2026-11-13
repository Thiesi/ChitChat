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

async function fixture(page, { current = settings({ username_reservation_days: 365 }) } = {}) {
  const requests = [];
  await page.clock.setFixedTime(new Date('2026-11-12T12:00:00Z'));
  await page.route('**/installation-icon.php*', (route) => route.fulfill({ contentType: 'image/png', body: PNG }));
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace('/api/v1/', '');
    if (request.method() === 'POST') requests.push({ path, body: request.postDataJSON?.() ?? null });
    if (path === 'admin/settings/update.php') {
      const body = request.postDataJSON();
      return route.fulfill({ json: { settings: { ...current, ...body, updated_at: '2026-11-12T10:00:00Z' } } });
    }
    const responses = {
      'session.php': { user: admin, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'admin/settings/get.php': { settings: current },
      'admin/settings/lockdown/get.php': { lockdown: { enabled: false, custom_message: null, since: null } },
      'admin/settings/lockdown/update.php': { lockdown: { enabled: true, custom_message: null, since: '2026-11-12T10:00:00Z', signed_out: 3 } },
      'admin/system-status.php': { status: SYSTEM_STATUS },
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
    await fixture(page);
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
