import { expect, test } from '@playwright/test';
import { attemptName } from './support/attempt.js';

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
  await expect(page.locator('#chat-shell')).toBeVisible();
}

// Deleting and restoring need privileged step-up; a recent one may still be valid.
async function passStepUp(page, password) {
  const dialog = page.locator('.step-up-dialog');
  try {
    await dialog.waitFor({ state: 'visible', timeout: 3_000 });
  } catch {
    return;
  }
  await dialog.locator('#step-up-password').fill(password);
  await dialog.getByRole('button', { name: 'Verify password' }).click();
  await expect(dialog).toBeHidden();
}

test('a deleted room disappears for members at once and can be restored', async ({ browser }) => {
  const rootContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });
  const roomName = `Doomed ${attemptName('Den')}`;

  try {
    const rootPage = await rootContext.newPage();
    await login(rootPage, root);
    await rootPage.locator('#new-room-button').click();
    const roomDialog = rootPage.locator('#room-dialog');
    await roomDialog.locator('#room-key').fill(attemptName('doomed-den-e2e').toLowerCase());
    await roomDialog.locator('#room-name').fill(roomName);
    await roomDialog.getByRole('button', { name: 'Create room' }).click();
    await expect(rootPage.locator('#room-title')).toHaveText(`# ${roomName}`);

    const memberPage = await memberContext.newPage();
    await login(memberPage, member);
    const memberRoom = memberPage.locator('.room-button', { hasText: roomName });
    await expect(memberRoom).toBeVisible();

    await rootPage.goto('/admin.php');
    await rootPage.locator('#rooms-tab').click();
    // The picker's label wraps the select, so its text includes every room name; address it directly.
    await rootPage.locator('#room-picker').selectOption({ label: `# ${roomName}` });
    await expect(rootPage.locator('#admin-room-name')).toHaveValue(roomName);
    rootPage.once('dialog', (dialog) => dialog.accept());
    await rootPage.getByRole('button', { name: 'Delete room' }).click();
    await passStepUp(rootPage, root.password);

    const deleted = rootPage.locator('#deleted-room-list .admin-list-item', { hasText: roomName });
    await expect(deleted).toContainText('removed permanently after');
    await expect(memberRoom).toHaveCount(0);

    await deleted.getByRole('button', { name: 'Restore' }).click();
    await passStepUp(rootPage, root.password);
    await expect(deleted).toHaveCount(0);
    await expect(memberRoom).toBeVisible();

    // Leave nothing behind for later specs.
    rootPage.once('dialog', (dialog) => dialog.accept());
    await rootPage.getByRole('button', { name: 'Delete room' }).click();
    await passStepUp(rootPage, root.password);
    await expect(deleted).toBeVisible();
  } finally {
    await rootContext.close();
    await memberContext.close();
  }
});
