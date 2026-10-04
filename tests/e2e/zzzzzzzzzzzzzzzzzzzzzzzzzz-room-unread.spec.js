import { expect, test } from '@playwright/test';
import { attemptName } from './support/attempt.js';
import { openRoom } from './support/rooms.js';

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

async function send(page, text) {
  await page.locator('#composer-input').fill(text);
  await page.locator('#composer-input').press('Enter');
  await expect(page.locator('.message-body', { hasText: text })).toBeVisible();
}

test('unread messages show in the room list and behind a divider until read', async ({ browser }) => {
  const rootContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });
  const roomName = `Unread ${attemptName('Nook')}`;

  try {
    const rootPage = await rootContext.newPage();
    await login(rootPage, root);
    await rootPage.locator('#new-room-button').click();
    const roomDialog = rootPage.locator('#room-dialog');
    await roomDialog.locator('#room-key').fill(attemptName('unread-nook-e2e').toLowerCase());
    await roomDialog.locator('#room-name').fill(roomName);
    await roomDialog.getByRole('button', { name: 'Create room' }).click();
    await expect(rootPage.locator('#room-title')).toHaveText(`# ${roomName}`);
    await send(rootPage, 'Already here before you joined');

    // The member joins, reads, and then looks at another room.
    const memberPage = await memberContext.newPage();
    await login(memberPage, member);
    const room = memberPage.locator('.room-button', { hasText: roomName });
    await openRoom(memberPage, roomName);
    const firstRead = memberPage.waitForResponse((response) => response.url().includes('/api/v1/rooms/read.php'));
    await memberPage.locator('#join-button').click();
    await expect(memberPage.locator('#composer-input')).toBeVisible();
    await firstRead;
    const elsewhere = await memberPage.locator('.room-button').filter({ hasNotText: roomName }).first().locator('.room-name').textContent();
    await openRoom(memberPage, elsewhere.replace(/^#\s*/u, '').trim());
    await expect(room).not.toHaveClass(/active/);

    await send(rootPage, 'First thing you missed');
    await send(rootPage, 'Second thing you missed');
    await expect(room.locator('.unread-count')).toHaveText('2 unread');
    await expect(room).toHaveClass(/unread/);

    // Opening the room shows where the new messages begin, then marks them read.
    const marked = memberPage.waitForResponse((response) => response.url().includes('/api/v1/rooms/read.php'));
    await openRoom(memberPage, roomName);
    const divider = memberPage.locator('.new-messages-divider');
    await expect(divider).toBeVisible();
    await expect(memberPage.locator('.new-messages-divider + .message')).toContainText('First thing you missed');
    await expect(room.locator('.unread-count')).toHaveCount(0);
    await marked;

    // The read position is kept on the server, so a reload shows nothing unread.
    await memberPage.reload();
    await expect(memberPage.locator('#chat-shell')).toBeVisible();
    await expect(memberPage.locator('.room-button', { hasText: roomName }).locator('.unread-count')).toHaveCount(0);
  } finally {
    await rootContext.close();
    await memberContext.close();
  }
});
