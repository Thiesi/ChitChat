import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// The sign-in decoration on Operational settings (#242; S3's drawings):
// chosen by hand, previewed around a small lockup, saved with a step-up.
// Presentation only: the real page against intercepted APIs.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const admin = { id: 1, username: 'Root', roles: ['super_admin'], session_version: 1 };
const OPTIONS = ['off', 'winter', 'spring', 'celebration'];
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

async function fixture(page, { value = 'off', decoration = 'ok', update = null } = {}) {
  const requests = [];
  await page.route('**/installation-icon.php*', (route) => route.fulfill({ contentType: 'image/png', body: PNG }));
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace('/api/v1/', '');
    if (request.method() === 'POST') requests.push({ path, body: request.postDataJSON?.() ?? null });
    if (path === 'admin/settings/sign-in-decoration/get.php') {
      if (decoration === 'missing') return route.fulfill({ status: 404, json: { error: { code: 'not_found', message: 'No.' } } });
      return route.fulfill({ json: { sign_in_decoration: { value, options: OPTIONS } } });
    }
    if (path === 'admin/settings/sign-in-decoration/update.php') {
      if (update) return route.fulfill(update(request));
      return route.fulfill({ json: { sign_in_decoration: { value: request.postDataJSON().sign_in_decoration, options: OPTIONS } } });
    }
    const responses = {
      'session.php': { user: admin, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'admin/settings/get.php': { settings: { registration_enabled: true, guest_access_enabled: false, mfa_required_for_admin_roles: false, audit_retention_days: 365, deleted_attachment_retention_days: 30, deleted_room_grace_days: 30, direct_message_retention_days: 0, login_attempt_retention_days: 30, orphan_attachment_grace_hours: 24, realtime_event_retention_hours: 24, room_message_retention_days: 0, updated_at: '2026-11-01T10:00:00Z' } },
      'admin/settings/lockdown/get.php': { lockdown: { enabled: false, custom_message: null, since: null } },
      'admin/settings/application-name/get.php': { application_name: { default: 'ChitChat', override: null } },
      'admin/settings/registration-protection/get.php': { registration_protection: { overrides: { rate_limit_max_attempts: null, rate_limit_window_seconds: null, min_fill_seconds: null, proof_of_work_bits: null }, defaults: { rate_limit_max_attempts: 5, rate_limit_window_seconds: 3600, min_fill_seconds: 3, proof_of_work_bits: 16 }, effective: { rate_limit_max_attempts: 5, rate_limit_window_seconds: 3600, min_fill_seconds: 3, proof_of_work_bits: 16 } } },
      'admin/policies.php': { policies: { rules: { source: 'none', markdown: '', url: null, version: 0, updated_at: null }, privacy: { source: 'none', markdown: '', url: null, version: 0, updated_at: null }, legal_notice: { url: null } } },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'account/mfa/status.php': { mfa: { enabled: false, available: false, credentials: [] } },
      'account/identities/list.php': { providers: [], identities: [], has_password: true },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return requests;
}

const card = (page) => page.locator('#decoration-settings');
const select = (page) => card(page).getByRole('combobox', { name: 'Decoration' });

async function open(page) {
  await page.goto('/admin-settings.php');
  await expect(card(page)).toBeVisible();
}

test('the card offers the server’s choices in order, with the saved one chosen and nothing to save yet', async ({ page }) => {
  await fixture(page, { value: 'spring' });
  await open(page);
  expect(await select(page).locator('option').allTextContents()).toEqual(['None', 'Winter: snowflakes', 'Spring: blossoms', 'Celebration: confetti']);
  await expect(select(page)).toHaveValue('spring');
  await expect(card(page).getByRole('button', { name: 'Save decoration' })).toBeDisabled();
  // The preview draws the saved decoration, out of the accessibility tree.
  await expect(card(page).locator('.decoration-preview')).toHaveAttribute('aria-hidden', 'true');
  await expect(card(page).locator('[data-decoration-layer]')).toHaveAttribute('data-season-kind', 'spring');
});

test('choosing previews at once; "Save decoration" saves exactly that choice', async ({ page }) => {
  const requests = await fixture(page);
  await open(page);
  await expect(card(page).locator('[data-decoration-layer]')).toBeHidden();
  await select(page).selectOption('winter');
  await expect(card(page).locator('[data-decoration-layer]')).toHaveAttribute('data-season-kind', 'winter');
  await expect(card(page).locator('#decoration-status')).toHaveText('Not saved yet.');
  expect(requests.some((entry) => entry.path.includes('sign-in-decoration/update'))).toBe(false);
  await card(page).getByRole('button', { name: 'Save decoration' }).click();
  await expect(card(page).locator('#decoration-status')).toHaveText('Saved. The sign-in page shows the winter decoration from its next load.');
  expect(requests.find((entry) => entry.path.includes('sign-in-decoration/update')).body).toEqual({ sign_in_decoration: 'winter' });
  await expect(card(page).getByRole('button', { name: 'Save decoration' })).toBeDisabled();

  await select(page).selectOption('off');
  await expect(card(page).locator('[data-decoration-layer]')).toBeHidden();
  await card(page).getByRole('button', { name: 'Save decoration' }).click();
  await expect(card(page).locator('#decoration-status')).toHaveText('Saved. The sign-in page has no decoration.');
});

test('saving asks to confirm it’s you first, then saves', async ({ page }) => {
  let confirmed = false;
  const requests = await fixture(page, { update: (request) => (confirmed
    ? { json: { sign_in_decoration: { value: request.postDataJSON().sign_in_decoration, options: OPTIONS } } }
    : { status: 403, json: { error: { code: 'step_up_required', message: 'Confirm it’s you.' } } }) });
  await page.route('**/api/v1/step-up.php', (route) => { confirmed = true; return route.fulfill({ json: { step_up: { expires_at: '2026-11-12T12:00:00Z' } } }); });
  await open(page);
  await select(page).selectOption('celebration');
  await card(page).getByRole('button', { name: 'Save decoration' }).click();
  const dialog = page.getByRole('dialog', { name: 'Confirm this sensitive action' });
  await dialog.locator('#step-up-password').fill('a long secret phrase');
  await dialog.getByRole('button', { name: 'Verify password' }).click();
  await expect(card(page).locator('#decoration-status')).toHaveText('Saved. The sign-in page shows the celebration decoration from its next load.');
  expect(requests.filter((entry) => entry.path.includes('sign-in-decoration/update')).map((entry) => entry.body.sign_in_decoration)).toEqual(['celebration', 'celebration']);
});

test('a refused save says so and keeps the choice to try again', async ({ page }) => {
  await fixture(page, { update: () => ({ status: 500, json: { error: { code: 'server_error', message: 'No.' } } }) });
  await open(page);
  await select(page).selectOption('spring');
  await card(page).getByRole('button', { name: 'Save decoration' }).click();
  await expect(card(page).locator('#decoration-error')).toHaveText('That didn’t save. Try again.');
  await expect(select(page)).toHaveValue('spring');
  await expect(card(page).getByRole('button', { name: 'Save decoration' })).toBeEnabled();
});

test('no card when the server doesn’t offer the setting', async ({ page }) => {
  await fixture(page, { decoration: 'missing' });
  await page.goto('/admin-settings.php');
  await expect(page.locator('#settings-shell')).toBeVisible();
  await expect(card(page)).toBeHidden();
});

for (const theme of ['light', 'dark']) {
  test(`${theme}: the card passes axe at 1280 and 360px with a decoration previewed`, async ({ page }) => {
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await fixture(page, { value: 'celebration' });
    for (const width of [1280, 360]) {
      await page.setViewportSize({ width, height: 900 });
      await open(page);
      await card(page).scrollIntoViewIfNeeded();
      await page.mouse.move(0, 0);
      const result = await new AxeBuilder({ page }).include('#decoration-settings').withTags(WCAG).analyze();
      expect(result.violations, `${width}`).toEqual([]);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    }
  });
}
