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

async function openGeneral(page, account) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.locator('#login-username').fill(account.username);
  await page.locator('#login-password').fill(account.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
  await page.locator('.room-button', { hasText: '# General E2E' }).click();
  await expect(page.locator('#room-title')).toHaveText('# General E2E');
}

test('a ping is a private notice for both people and reaches a target who was offline', async ({ browser }) => {
  const rootContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });
  const text = attemptText('Ping while you were away');

  try {
    // The member is signed out while the ping is sent.
    const rootPage = await rootContext.newPage();
    await openGeneral(rootPage, root);
    await rootPage.locator('#composer-input').fill(`/ping ${member.username} ${text}`);
    await rootPage.locator('#composer-input').press('Enter');
    const sent = rootPage.locator('.ping-notice', { hasText: text });
    await expect(sent).toContainText(`You pinged ${member.username}`);

    const page = await memberContext.newPage();
    await openGeneral(page, member);
    const received = page.locator('.ping-notice', { hasText: text });
    await expect(received).toContainText(`${root.username} pinged you`);
    await expect(received).toContainText('only you two see this');

    await page.getByRole('button', { name: /^Notifications, \d+ unread$/ }).click();
    await expect(page.locator('#notifications-preview')).toContainText(`${root.username} pinged you`);
    await page.keyboard.press('Escape');

    // The notice survives a reload: it is stored, not just a live event.
    await page.reload();
    await page.locator('.room-button', { hasText: '# General E2E' }).click();
    await expect(page.locator('.ping-notice', { hasText: text })).toBeVisible();
  } finally {
    await rootContext.close();
    await memberContext.close();
  }
});
