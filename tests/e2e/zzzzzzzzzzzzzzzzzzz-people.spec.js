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
  await expect(page.locator('#chat-shell')).toBeVisible();
  await page.locator('.room-button', { hasText: '# General E2E' }).click();
  await expect(page.locator('#room-title')).toHaveText('# General E2E');
}

test('a name opens a menu that starts a direct message, and Tab completes names', async ({ browser }) => {
  const rootContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });
  const greeting = attemptText('Name menu hello');

  try {
    const rootPage = await rootContext.newPage();
    await login(rootPage, root);
    await rootPage.locator('#composer-input').fill(greeting);
    await rootPage.locator('#composer-input').press('Enter');
    await expect(rootPage.locator('.message-body', { hasText: greeting })).toBeVisible();

    const page = await memberContext.newPage();
    await login(page, member);
    const message = page.locator('.message', { has: page.locator('.message-body', { hasText: greeting }) });
    const author = message.getByRole('button', { name: root.username });
    await author.click();

    const menu = page.locator('#name-menu');
    const sendDirectMessage = menu.getByRole('link', { name: 'Send direct message' });
    await expect(sendDirectMessage).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await expect(author).toBeFocused();

    // Tab completes a recent speaker IRC-style; with no match it leaves the field.
    const input = page.locator('#composer-input');
    await input.fill('');
    await input.pressSequentially('Roo');
    await input.press('Tab');
    await expect(input).toHaveValue(`${root.username}: `);
    await input.fill('zzqx');
    await input.press('Tab');
    await expect(input).not.toBeFocused();

    await author.click();
    await sendDirectMessage.click();
    await expect(page).toHaveURL(/\/messages\.php\?with=\d+$/);
    await expect(page.locator('#dm-peer-name')).toHaveText(root.username);
    await expect(page.locator('#dm-message-input')).toBeVisible();
  } finally {
    await rootContext.close();
    await memberContext.close();
  }
});
