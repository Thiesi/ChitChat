import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// Operational settings: the username reservation (#164; system-settings.md),
// operable inside ChitChat like every other setting. Presentation only: the
// real page against intercepted APIs.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const admin = { id: 1, username: 'Root', roles: ['super_admin'], session_version: 1 };
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const settings = (extra = {}) => ({
  registration_enabled: true, guest_access_enabled: false, mfa_required_for_admin_roles: false,
  audit_retention_days: 0, deleted_attachment_retention_days: 0, deleted_room_grace_days: 30,
  direct_message_retention_days: 0, login_attempt_retention_days: 30, orphan_attachment_grace_hours: 24,
  realtime_event_retention_hours: 24, room_message_retention_days: 0, updated_at: '2026-11-01T10:00:00Z', ...extra,
});

async function fixture(page, { current = settings({ username_reservation_days: 365 }) } = {}) {
  const requests = [];
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
