import { expect, test } from '@playwright/test';

const member = {
  username: 'MemberE2E',
  password: 'Another Correct Horse Battery Staple 2026!',
};

async function signIn(page) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.locator('#login-username').fill(member.username);
  await page.locator('#login-password').fill(member.password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
}

test('account closure blocks ordinary login and supports explicit cooling-off restoration', async ({ page }) => {
  await signIn(page);
  await page.getByRole('link', { name: 'Account' }).click();
  await expect(page).toHaveURL(/\/account\.php$/);

  await page.getByLabel(/I understand that I will be signed out immediately/).check();
  await page.getByRole('button', { name: 'Request account closure' }).click();

  const dialog = page.getByRole('dialog', { name: 'Confirm this sensitive action' });
  await expect(dialog).toBeVisible();
  await dialog.getByLabel('Current password').fill(member.password);
  await dialog.getByRole('button', { name: 'Verify password' }).click();

  await expect(page.locator('#account-closure-status')).toContainText('Closure requested.');
  await expect(page).toHaveURL(/\/$/);
  await expect(page.locator('#auth-shell')).toBeVisible();

  await page.locator('#login-username').fill(member.username);
  await page.locator('#login-password').fill(member.password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('#auth-error')).toContainText('scheduled for closure');
  await expect(page.locator('#chat-shell')).toBeHidden();

  await page.getByRole('link', { name: 'Restore a closing account' }).click();
  await expect(page).toHaveURL(/\/restore-account\.php$/);
  await page.locator('#restore-username').fill(member.username);
  await page.locator('#restore-password').fill(member.password);
  await page.getByRole('button', { name: 'Restore account' }).click();

  await expect(page).toHaveURL(/\/$/);
  await expect(page.locator('#chat-shell')).toBeVisible();
  await expect(page.locator('#current-user')).toHaveText(member.username);
});
