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

// Later specs sign in as MemberE2E, so a failure after closure must not leave it closed.
async function restoreAccount(request) {
  const session = await (await request.get('/api/v1/session.php')).json();
  await request.post('/api/v1/account/restore.php', {
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': session.csrf_token },
    data: { username: member.username, password: member.password },
  });
}

test('account closure blocks ordinary login and supports explicit cooling-off restoration', async ({ page }) => {
  let closureRequested = false;
  try {
    await closeAndRestore(page, () => { closureRequested = true; });
    closureRequested = false;
  } finally {
    if (closureRequested) {
      await restoreAccount(page.context().request).catch(() => {});
    }
  }
});

async function closeAndRestore(page, onClosureRequested) {
  await signIn(page);
  await page.getByRole('link', { name: 'Account' }).click();
  await expect(page).toHaveURL(/\/account\.php$/);

  await page.getByLabel(/I understand that I will be signed out immediately/).check();
  await page.getByRole('button', { name: 'Request account closure' }).click();

  const dialog = page.getByRole('dialog', { name: 'Confirm this sensitive action' });
  await expect(dialog).toBeVisible();
  await dialog.getByLabel('Current password').fill(member.password);
  onClosureRequested();
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
}
