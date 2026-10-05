import { expect, test } from '@playwright/test';
import { attemptName } from './support/attempt.js';

const baseURL = process.env.CHITCHAT_BASE_URL ?? 'http://127.0.0.1:8080';
const root = {
  username: 'RootE2E',
  password: 'Correct Horse Battery Staple 2026!',
};

test('an administrator creates a room from Administration and lands on its settings', async ({ browser }) => {
  const context = await browser.newContext({ baseURL });
  try {
    const page = await context.newPage();
    await page.goto('/');
    await page.locator('#login-username').fill(root.username);
    await page.locator('#login-password').fill(root.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page.locator('#chat-shell')).toBeVisible();

    await page.goto('/admin.php');
    await page.locator('#rooms-tab').click();
    await page.getByRole('button', { name: 'Create room' }).click();
    const dialog = page.getByRole('dialog', { name: 'Create room' });
    const name = `Planning ${attemptName('Desk')}`;
    await dialog.getByLabel(/Room key/).fill(attemptName('planning-desk-e2e').toLowerCase());
    await dialog.getByLabel('Name').fill(name);
    await dialog.getByLabel('Visibility').selectOption('private');
    await expect(dialog.getByLabel(/Guests/)).toBeDisabled();
    await dialog.getByRole('button', { name: 'Create room' }).click();
    await expect(dialog).toBeHidden();

    await expect(page.locator('#room-picker option:checked')).toHaveText(`# ${name}`);
    await expect(page.locator('#admin-room-name')).toHaveValue(name);
    await expect(page.locator('#admin-room-visibility')).toHaveValue('private');
  } finally {
    await context.close();
  }
});
