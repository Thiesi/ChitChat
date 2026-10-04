import { expect, test } from '@playwright/test';
import { attemptName, attemptText } from './support/attempt.js';

const baseURL = process.env.CHITCHAT_BASE_URL ?? 'http://127.0.0.1:8080';
const root = {
  username: 'RootE2E',
  password: 'Correct Horse Battery Staple 2026!',
};
const member = {
  username: 'MemberE2E',
  password: 'Another Correct Horse Battery Staple 2026!',
};

async function selectDirectMessagePeer(page, username) {
  await page.locator('#dm-user-search').fill(username);
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  await page.locator('.dm-user-button', { hasText: username }).click();
  await expect(page.locator('#dm-peer-name')).toHaveText(username);
  await expect(page.locator('#dm-block-toggle')).toBeVisible();
}

async function login(page, account) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.locator('#login-username').fill(account.username);
  await page.locator('#login-password').fill(account.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
}

test('the other side sees who is typing until their message arrives', async ({ browser }) => {
  const rootContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });
  const roomName = `Typing ${attemptName('Nook')}`;

  try {
    const rootPage = await rootContext.newPage();
    await login(rootPage, root);
    await rootPage.locator('#new-room-button').click();
    const roomDialog = rootPage.locator('#room-dialog');
    await roomDialog.locator('#room-key').fill(attemptName('typing-nook-e2e').toLowerCase());
    await roomDialog.locator('#room-name').fill(roomName);
    await roomDialog.getByRole('button', { name: 'Create room' }).click();
    await expect(rootPage.locator('#room-title')).toHaveText(`# ${roomName}`);

    const memberPage = await memberContext.newPage();
    await login(memberPage, member);
    await memberPage.locator('.room-button', { hasText: roomName }).click();
    await memberPage.locator('#join-button').click();
    await expect(memberPage.locator('#composer-input')).toBeVisible();

    // A command being typed is nobody's business: no signal goes out.
    let signals = 0;
    memberPage.on('request', (request) => {
      if (request.url().includes('/api/v1/typing.php')) signals += 1;
    });
    await memberPage.locator('#composer-input').pressSequentially('/shrug');
    await memberPage.waitForTimeout(500);
    expect(signals).toBe(0);
    await memberPage.locator('#composer-input').fill('');
    const indicator = rootPage.locator('#typing-indicator');

    const text = attemptText('Typed slowly on purpose');
    await memberPage.locator('#composer-input').fill(text);
    await expect(indicator).toHaveText(`${member.username} is typing…`);
    expect(signals).toBe(1);
    await memberPage.locator('#composer-input').press('Enter');
    await expect(rootPage.locator('.message-body', { hasText: text })).toBeVisible();
    await expect(indicator).toHaveText('');

    // Direct conversations show it too, to the other person only.
    await rootPage.goto('/messages.php');
    await selectDirectMessagePeer(rootPage, member.username);
    await memberPage.goto('/messages.php');
    await selectDirectMessagePeer(memberPage, root.username);
    await expect(memberPage.locator('#dm-message-input')).toBeEnabled();
    await memberPage.locator('#dm-message-input').fill(attemptText('Almost done'));
    await expect(rootPage.locator('#dm-typing-indicator')).toHaveText(`${member.username} is typing…`);
  } finally {
    await rootContext.close();
    await memberContext.close();
  }
});
