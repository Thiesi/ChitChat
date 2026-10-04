import { expect, test } from '@playwright/test';
import { attemptText } from './support/attempt.js';

const baseURL = process.env.CHITCHAT_BASE_URL ?? 'http://127.0.0.1:8080';
const root = {
  username: 'RootE2E',
  password: 'Correct Horse Battery Staple 2026!',
};
const member = {
  username: 'MemberE2E',
  password: 'Another Correct Horse Battery Staple 2026!',
};

async function login(page, account) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.locator('#login-username').fill(account.username);
  await page.locator('#login-password').fill(account.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
}

// Saving the lockdown needs privileged step-up; a recent one may still be valid.
async function saveLockdown(page, enabled, message) {
  await page.locator('#lockdown-enabled').selectOption(enabled ? '1' : '0');
  await page.locator('#lockdown-message').fill(message);
  await page.locator('#save-lockdown').click();
  const dialog = page.locator('.step-up-dialog');
  try {
    await dialog.waitFor({ state: 'visible', timeout: 3_000 });
    await dialog.locator('#step-up-password').fill(root.password);
    await dialog.getByRole('button', { name: 'Verify password' }).click();
    await expect(dialog).toBeHidden();
  } catch {
    // No step-up prompt was needed.
  }
  await expect(page.locator('#lockdown-state')).toContainText(enabled ? 'Lockdown is ON' : 'Lockdown is off');
}

test('a maintenance lockdown keeps everyone but Super-Administrators out and says why', async ({ browser }) => {
  const rootContext = await browser.newContext({ baseURL });
  const visitorContext = await browser.newContext({ baseURL });
  const message = attemptText('Database upgrade until 22:30');
  const rootPage = await rootContext.newPage();

  try {
    await login(rootPage, root);
    await expect(rootPage.locator('#chat-shell')).toBeVisible();
    await rootPage.goto('/admin-settings.php');
    await expect(rootPage.locator('#settings-shell')).toBeVisible();
    await saveLockdown(rootPage, true, message);

    const visitor = await visitorContext.newPage();
    await visitor.goto('/');
    await expect(visitor.locator('#auth-lockdown')).toContainText(message);
    await expect(visitor.getByRole('tab', { name: 'Register' })).toBeHidden();
    await login(visitor, member);
    await expect(visitor.locator('#auth-error')).toContainText(message);
    await expect(visitor.locator('#chat-shell')).toBeHidden();

    // The Super-Administrator sees the banner in chat and can still sign in.
    await rootPage.goto('/');
    await expect(rootPage.locator('#chat-lockdown')).toContainText(message);
  } finally {
    await rootPage.goto('/admin-settings.php');
    await expect(rootPage.locator('#settings-shell')).toBeVisible();
    await saveLockdown(rootPage, false, '');
    await rootContext.close();
    await visitorContext.close();
  }

  // Afterwards everyone can sign in again.
  const page = await browser.newPage({ baseURL });
  await login(page, member);
  await expect(page.locator('#chat-shell')).toBeVisible();
  await expect(page.locator('#chat-lockdown')).toBeHidden();
  await page.close();
});
