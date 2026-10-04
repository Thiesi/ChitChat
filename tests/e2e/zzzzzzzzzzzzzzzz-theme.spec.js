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

const html = (page) => page.locator('html');

test('the theme follows the system by default and remembers an explicit choice on this device', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'light' });
  await login(page);
  await expect(html(page)).toHaveAttribute('data-theme', 'light');
  await page.emulateMedia({ colorScheme: 'dark' });
  await expect(html(page)).toHaveAttribute('data-theme', 'dark');

  const sidebarTheme = page.locator('.sidebar-footer').getByLabel('Theme');
  await expect(sidebarTheme).toHaveValue('system');
  await sidebarTheme.selectOption('light');
  await expect(html(page)).toHaveAttribute('data-theme', 'light');

  // The choice applies before paint on every page and survives reloads.
  await page.reload();
  await expect(html(page)).toHaveAttribute('data-theme', 'light');
  await page.goto('/account.php');
  await expect(html(page)).toHaveAttribute('data-theme', 'light');
  await expect(page.locator('#account-shell').getByLabel('Theme')).toHaveValue('light');

  await page.locator('#account-shell').getByLabel('Theme').selectOption('system');
  await expect(html(page)).toHaveAttribute('data-theme', 'dark');
  await page.emulateMedia({ colorScheme: 'light' });
  await expect(html(page)).toHaveAttribute('data-theme', 'light');
});
