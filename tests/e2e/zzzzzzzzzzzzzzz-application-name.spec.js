import { expect, test } from '@playwright/test';
import { attemptText } from './support/attempt.js';

const baseURL = process.env.CHITCHAT_BASE_URL ?? 'http://127.0.0.1:8080';
const root = {
  username: 'RootE2E',
  password: 'Correct Horse Battery Staple 2026!',
};

test('Super-Administrator renames the installation and every page presents the new name', async ({ browser }) => {
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();
  const name = attemptText('Harbor Chat');
  try {
    await page.goto('/');
    await page.locator('#login-username').fill(root.username);
    await page.locator('#login-password').fill(root.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page.locator('#chat-shell')).toBeVisible();
    const defaultName = await page.locator('meta[name="application-name"]').getAttribute('content');

    await page.goto('/admin-settings.php');
    await expect(page.getByRole('heading', { name: 'Application name' })).toBeVisible();
    await expect(page.locator('#app-name')).toHaveValue('');
    await expect(page.locator('#app-name')).toHaveAttribute('placeholder', defaultName);

    await page.locator('#app-name').fill(name);
    await page.getByRole('button', { name: 'Save application name' }).click();
    const stepUpDialog = page.locator('.step-up-dialog');
    await expect(stepUpDialog).toBeVisible();
    await stepUpDialog.locator('#step-up-password').fill(root.password);
    await stepUpDialog.getByRole('button', { name: 'Verify password' }).click();
    await expect(page.locator('#toast-region')).toContainText('Application name saved.');

    await page.goto('/');
    await expect(page).toHaveTitle(name);
    await expect(page.locator('.sidebar-header h1')).toHaveText(name);
    await expect(page.locator('meta[name="application-name"]')).toHaveAttribute('content', name);

    await page.goto('/notifications.php');
    await expect(page.locator('meta[name="application-name"]')).toHaveAttribute('content', name);

    await page.goto('/admin-settings.php');
    await page.locator('#app-name').fill('');
    await page.getByRole('button', { name: 'Save application name' }).click();
    await expect(page.locator('#app-name')).toHaveValue('');
    await page.goto('/');
    await expect(page.locator('meta[name="application-name"]')).toHaveAttribute('content', defaultName);
  } finally {
    // Other specs and the visual baselines expect the default name.
    const session = await (await context.request.get('/api/v1/session.php')).json();
    const headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': session.csrf_token };
    await context.request.post('/api/v1/step-up.php', { headers, data: { password: root.password } }).catch(() => {});
    await context.request.post('/api/v1/admin/settings/application-name/update.php', {
      headers,
      data: { application_name: null },
    }).catch(() => {});
    await context.close();
  }
});
