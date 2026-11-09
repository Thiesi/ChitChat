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

  // A colour scheme chosen on You applies everywhere on this device (the account menu went in #168, S2b).
  await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'You', exact: true }).click();
  const accountSchemes = page.locator('#account-shell').getByRole('radiogroup', { name: 'Colour scheme' });
  await accountSchemes.getByRole('radio', { name: 'Dusk' }).check();
  await expect(page.locator('html')).toHaveAttribute('data-scheme', 'dusk');
  await page.goto('/');
  await expect(page.locator('html')).toHaveAttribute('data-scheme', 'dusk');
  await page.goto('/account.php');
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
    // Since S1c (#166) the day heading above a day's messages carries the
    // date, so a message shows only its time, on the account's clock; the
    // full stamp in the account format is its title.
    const time = page.locator('.message-time').first();
    await expect(time).toHaveText(/^\d{2}:\d{2}$/);
    await expect(time).toHaveAttribute('title', /^\d{2}\.\d{2}\.\d{2}, \d{2}:\d{2}$/);
  } finally {
    // Other specs share this account and expect the defaults.
    await page.goto('/account.php');
    await page.getByLabel('Format region').selectOption('');
    await page.getByLabel('Clock').selectOption('');
    await page.getByRole('button', { name: 'Save date and time format' }).click();
    await expect(page.locator('#date-time-status')).toContainText('Saved');
    await page.locator('#account-shell').getByRole('radiogroup', { name: 'Colour scheme' }).getByRole('radio', { name: 'Lantern' }).check();
    // Lantern is the default (S0, #167), so it sets no data-scheme.
    await expect(page.locator('html')).not.toHaveAttribute('data-scheme', /.+/);
  }
});
