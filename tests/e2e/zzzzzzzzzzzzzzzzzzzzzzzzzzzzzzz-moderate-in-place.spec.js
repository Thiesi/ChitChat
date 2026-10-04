import { expect, test } from '@playwright/test';
import { attemptName, attemptText } from './support/attempt.js';
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

test('a moderator deletes someone else\'s message right in the chat', async ({ browser }) => {
  const rootContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });
  const roomName = `Moderated ${attemptName('Hall')}`;

  try {
    const rootPage = await rootContext.newPage();
    await login(rootPage, root);
    await rootPage.locator('#new-room-button').click();
    const roomDialog = rootPage.locator('#room-dialog');
    await roomDialog.locator('#room-key').fill(attemptName('moderated-hall-e2e').toLowerCase());
    await roomDialog.locator('#room-name').fill(roomName);
    await roomDialog.getByRole('button', { name: 'Create room' }).click();
    await expect(rootPage.locator('#room-title')).toHaveText(`# ${roomName}`);

    const memberPage = await memberContext.newPage();
    await login(memberPage, member);
    await openRoom(memberPage, roomName);
    await memberPage.locator('#join-button').click();
    const text = attemptText('Buy cheap accounts here');
    await memberPage.locator('#composer-input').fill(text);
    await memberPage.locator('#composer-input').press('Enter');

    // The Super-Administrator sees Delete on the member's message, and only a confirmation follows.
    // Held by its ID: once deleted, the text it was found by is gone.
    const messageId = await rootPage.locator('.message', { hasText: text }).getAttribute('data-message-id');
    const message = rootPage.locator(`.message[data-message-id="${messageId}"]`);
    await message.getByRole('button', { name: 'Delete' }).click();
    const dialog = rootPage.getByRole('dialog', { name: 'Delete this message?' });
    await expect(dialog).toContainText(text);
    await dialog.getByLabel(/Reason/).fill('Spam');
    await dialog.getByRole('button', { name: 'Delete message' }).click();
    await expect(dialog).toBeHidden();
    await expect(message).toContainText('Message deleted by a moderator.');
    await expect(rootPage.locator('.step-up-dialog')).toBeHidden();

    // Everyone in the room sees it go, live.
    await expect(memberPage.locator('.message-body', { hasText: text })).toHaveCount(0);
    await expect(memberPage.locator('.message-body', { hasText: 'Message deleted by a moderator.' }).last()).toBeVisible();
  } finally {
    await rootContext.close();
    await memberContext.close();
  }
});
