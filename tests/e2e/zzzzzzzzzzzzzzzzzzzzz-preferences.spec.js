import { expect, test } from '@playwright/test';

const member = {
  username: 'MemberE2E',
  password: 'Another Correct Horse Battery Staple 2026!',
};

async function login(page) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.locator('#login-username').fill(member.username);
  await page.locator('#login-password').fill(member.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
}

test('colour schemes apply on every page, and dates follow the account format', async ({ page }) => {
  await login(page);

  // A colour scheme chosen in the account menu applies everywhere on this device.
  await page.getByRole('button', { name: 'Account menu' }).click();
  await page.getByRole('radiogroup', { name: 'Colour scheme' }).getByRole('radio', { name: 'Dusk' }).check();
  await expect(page.locator('html')).toHaveAttribute('data-scheme', 'dusk');
  await page.getByRole('link', { name: 'Account', exact: true }).click();
  await expect(page.locator('html')).toHaveAttribute('data-scheme', 'dusk');
  const accountSchemes = page.locator('#account-shell').getByRole('radiogroup', { name: 'Colour scheme' });
  await expect(accountSchemes.getByRole('radio', { name: 'Dusk' })).toBeChecked();

  try {
    // German dates on a 24-hour clock, whatever the browser's language.
    await page.getByLabel('Format region').selectOption('de-DE');
    await page.getByLabel('Clock').selectOption('h23');
    await expect(page.locator('#date-preview-medium')).toHaveText(/^\d{2}\.\d{2}\.\d{4}, \d{2}:\d{2}$/);
    await page.getByRole('button', { name: 'Save date and time format' }).click();
    await expect(page.locator('#date-time-status')).toContainText('Saved');

    await page.goto('/');
    await page.locator('.room-button', { hasText: '# General E2E' }).click();
    await expect(page.locator('.message-time').first()).toHaveText(/^\d{2}\.\d{2}\.\d{2}, \d{2}:\d{2}$/);
  } finally {
    // Other specs share this account and expect the defaults.
    await page.goto('/account.php');
    await page.getByLabel('Format region').selectOption('');
    await page.getByLabel('Clock').selectOption('');
    await page.getByRole('button', { name: 'Save date and time format' }).click();
    await expect(page.locator('#date-time-status')).toContainText('Saved');
    await page.locator('#account-shell').getByRole('radiogroup', { name: 'Colour scheme' }).getByRole('radio', { name: 'Lounge' }).check();
    await expect(page.locator('html')).not.toHaveAttribute('data-scheme', /.+/);
  }
});
