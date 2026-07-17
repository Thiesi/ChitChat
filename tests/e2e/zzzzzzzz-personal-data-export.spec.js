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
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
}

async function readDownload(download) {
  const stream = await download.createReadStream();
  const chunks = [];
  for await (const chunk of stream) chunks.push(chunk);
  return Buffer.concat(chunks).toString('utf8');
}

test('account page downloads a step-up-protected scoped JSON export', async ({ page }) => {
  await login(page);
  await page.getByRole('link', { name: 'Account' }).click();
  await expect(page).toHaveURL(/\/account\.php$/);
  await expect(page.getByRole('heading', { name: 'Your account', exact: true })).toBeVisible();
  await expect(page.locator('#account-identity')).toContainText(member.username);

  await page.getByText('What the export contains').click();
  await expect(page.getByText('It does not contain password hashes')).toBeVisible();

  await page.getByRole('button', { name: 'Download JSON export' }).click();
  const dialog = page.getByRole('dialog', { name: 'Confirm this sensitive action' });
  await expect(dialog).toBeVisible();
  await dialog.getByLabel('Current password').fill(member.password);

  const downloadPromise = page.waitForEvent('download');
  await dialog.getByRole('button', { name: 'Verify password' }).click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toMatch(/^chitchat-personal-data-MemberE2E-\d{8}-\d{6}\.json$/);

  const exported = JSON.parse(await readDownload(download));
  expect(exported.format).toEqual({
    name: 'chitchat-personal-data-export',
    version: 1,
  });
  expect(exported.account.username).toBe(member.username);
  expect(exported.account).not.toHaveProperty('password_hash');
  expect(exported.scope.excludes).toContain('attachment file bytes and internal attachment storage keys');
  expect(Array.isArray(exported.rooms.authored_messages)).toBe(true);
  expect(Array.isArray(exported.direct_messages.messages)).toBe(true);
  expect(Array.isArray(exported.activity)).toBe(true);
  expect(JSON.stringify(exported)).not.toContain('storage_key');

  await expect(page.locator('#personal-data-status')).toContainText(download.suggestedFilename());
});
