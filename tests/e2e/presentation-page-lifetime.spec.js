import { expect, test } from '@playwright/test';

// #270: leaving the page aborts requests still in flight, so no browser (WebKit above all)
// reports a navigation-cancelled fetch as an error. The abort rejects quietly with a
// PageLeftError: nothing is shown, but every finally still runs, so after Back from the
// back/forward cache the controls work again.
const admin = { id: 1, username: 'Root', roles: ['super_admin'], session_version: 1 };
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const current = {
  registration_enabled: true, guest_access_enabled: false, mfa_required_for_admin_roles: false,
  audit_retention_days: 0, deleted_attachment_retention_days: 0, deleted_room_grace_days: 30,
  direct_message_retention_days: 0, login_attempt_retention_days: 30, orphan_attachment_grace_hours: 24,
  realtime_event_retention_hours: 24, room_message_retention_days: 0, username_reservation_days: 365,
  updated_at: '2026-11-01T10:00:00Z',
};

/** Mocked admin settings; the first save is held open until the test lets it go. */
async function fixture(page) {
  const saves = [];
  let releaseFirst;
  const firstHeld = new Promise((resolve) => { releaseFirst = resolve; });
  await page.route('**/installation-icon.php*', (route) => route.fulfill({ contentType: 'image/png', body: PNG }));
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace('/api/v1/', '');
    if (path === 'admin/settings/update.php') {
      saves.push(request.postDataJSON());
      if (saves.length === 1) {
        await firstHeld;
        // The page aborted it long ago; fulfilling an aborted request may fail, which is fine.
        return route.fulfill({ json: { settings: current } }).catch(() => {});
      }
      return route.fulfill({ json: { settings: { ...current, ...request.postDataJSON() } } });
    }
    if (path === 'admin/settings/totp/get.php') return route.fulfill({ status: 403, json: { error: { code: 'forbidden', message: 'Forbidden.' } } });
    const responses = {
      'session.php': { user: admin, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'admin/settings/get.php': { settings: current },
      'admin/settings/lockdown/get.php': { lockdown: { enabled: false, custom_message: null, since: null } },
      'admin/settings/application-name/get.php': { application_name: { default: 'ChitChat', override: null } },
      'admin/settings/registration-protection/get.php': { registration_protection: {
        overrides: { rate_limit_max_attempts: null, rate_limit_window_seconds: null, min_fill_seconds: null, proof_of_work_bits: null },
        defaults: { rate_limit_max_attempts: 5, rate_limit_window_seconds: 3600, min_fill_seconds: 3, proof_of_work_bits: 16 },
        effective: { rate_limit_max_attempts: 5, rate_limit_window_seconds: 3600, min_fill_seconds: 3, proof_of_work_bits: 16 },
      } },
      'admin/policies.php': { policies: {
        rules: { source: 'none', markdown: '', url: null, version: 0, updated_at: null },
        privacy: { source: 'none', markdown: '', url: null, version: 0, updated_at: null }, legal_notice: { url: null },
      } },
      'admin/settings/sign-in-decoration/get.php': { sign_in_decoration: { value: 'off', options: ['off'] } },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return { saves, releaseFirst };
}

/** Every console error and uncaught page error, to assert that leaving logs nothing. */
function watchErrors(page) {
  const errors = [];
  // The fixture's own 403 for the authenticator card (it hides on 403) is expected noise.
  page.on('console', (message) => {
    if (message.type() === 'error' && !message.text().includes('status of 403')) errors.push(message.text());
  });
  page.on('pageerror', (error) => errors.push(String(error)));
  return errors;
}

const save = (page) => page.getByRole('button', { name: 'Save operational settings' });
const reservation = (page) => page.getByRole('spinbutton', { name: /Username reservation in days/ });

async function open(page) {
  await page.goto('/admin-settings.php');
  await expect(page.locator('#settings-shell')).toBeVisible();
  await expect(reservation(page)).toHaveValue('365');
}

const transition = (page, type) => page.evaluate((name) => {
  window.dispatchEvent(new PageTransitionEvent(name, { persisted: true }));
}, type);

test('Back from the back/forward cache: the save that was in flight re-enables its form, silently', async ({ page }) => {
  const { saves, releaseFirst } = await fixture(page);
  const errors = watchErrors(page);
  await open(page);

  await reservation(page).fill('30');
  await save(page).click();
  await expect.poll(() => saves.length).toBe(1);
  await expect(save(page)).toBeDisabled();

  // Leaving into the back/forward cache, then coming back.
  await transition(page, 'pagehide');
  await transition(page, 'pageshow');
  await expect(save(page)).toBeEnabled();
  await expect(page.locator('#settings-error')).toHaveText('');
  await expect(page.locator('.toast')).toHaveCount(0);
  releaseFirst();

  // A new save works.
  await save(page).click();
  await expect.poll(() => saves.length).toBe(2);
  await expect(page.locator('.toast', { hasText: /saved/i })).toBeVisible();
  await expect(save(page)).toBeEnabled();
  expect(errors).toEqual([]);
});

test('navigating away while a request is in flight logs no error', async ({ page }) => {
  const { saves, releaseFirst } = await fixture(page);
  const errors = watchErrors(page);
  await open(page);

  await reservation(page).fill('30');
  await save(page).click();
  await expect.poll(() => saves.length).toBe(1);
  // Leave the page for real while the save is still open.
  await page.goto('/admin-settings.php');
  await expect(reservation(page)).toHaveValue('365');
  releaseFirst();
  await page.waitForTimeout(500);
  expect(errors).toEqual([]);
});
