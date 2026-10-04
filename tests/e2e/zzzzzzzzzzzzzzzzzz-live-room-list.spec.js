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

test('a newly created public room appears for signed-in users without a reload', async ({ browser }) => {
  const rootContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });
  const roomName = `Live ${attemptName('Lounge')}`;

  try {
    const memberPage = await memberContext.newPage();
    await login(memberPage, member);
    await memberPage.locator('.room-button', { hasText: '# General E2E' }).click();
    await expect(memberPage.locator('#room-title')).toHaveText('# General E2E');
    await expect(memberPage.locator('#connection-status')).toHaveText('Live');
    await expect(memberPage.locator('.room-button', { hasText: roomName })).toHaveCount(0);

    const rootPage = await rootContext.newPage();
    await login(rootPage, root);
    await rootPage.locator('#new-room-button').click();
    const roomDialog = rootPage.locator('#room-dialog');
    await roomDialog.locator('#room-key').fill(attemptName('live-lounge-e2e').toLowerCase());
    await roomDialog.locator('#room-name').fill(roomName);
    await roomDialog.getByRole('button', { name: 'Create room' }).click();
    await expect(rootPage.locator('#room-title')).toHaveText(`# ${roomName}`);

    // The member's open tab picks the room up from the event stream, and stays where it was.
    await expect(memberPage.locator('.room-button', { hasText: roomName })).toBeVisible();
    await expect(memberPage.locator('#room-title')).toHaveText('# General E2E');
  } finally {
    await rootContext.close();
    await memberContext.close();
  }
});
