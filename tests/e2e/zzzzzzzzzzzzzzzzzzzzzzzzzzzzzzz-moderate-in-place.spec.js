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

test('a room owner mutes someone from the profile card and the room is told', async ({ browser }) => {
  const rootContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });
  const roomName = `Muted ${attemptName('Hall')}`;

  try {
    const rootPage = await rootContext.newPage();
    await login(rootPage, root);
    await rootPage.locator('#new-room-button').click();
    const roomDialog = rootPage.locator('#room-dialog');
    await roomDialog.locator('#room-key').fill(attemptName('muted-hall-e2e').toLowerCase());
    await roomDialog.locator('#room-name').fill(roomName);
    await roomDialog.getByRole('button', { name: 'Create room' }).click();
    await expect(rootPage.locator('#room-title')).toHaveText(`# ${roomName}`);

    const memberPage = await memberContext.newPage();
    await login(memberPage, member);
    await openRoom(memberPage, roomName);
    await memberPage.locator('#join-button').click();
    const hello = attemptText('Hello before the mute');
    await memberPage.locator('#composer-input').fill(hello);
    await memberPage.locator('#composer-input').press('Enter');

    // From the profile card behind the member's name: Mute in this room, for 1 hour, telling the room.
    await rootPage.locator('.message', { hasText: hello }).locator('.user-name', { hasText: member.username }).first().click();
    await rootPage.getByRole('button', { name: `Mute in #${roomName}…` }).click();
    const dialog = rootPage.getByRole('dialog', { name: `Mute ${member.username} in #${roomName}?` });
    // A radio, not the room-notice checkbox, whose text also says "for 1 hour".
    await dialog.getByRole('radio', { name: '1 hour', exact: true }).check();
    await dialog.getByRole('textbox').fill('Cool down, please');
    await dialog.getByRole('checkbox').check();
    await dialog.getByRole('button', { name: `Mute ${member.username}` }).click();
    await expect(dialog).toBeHidden();

    // The room sees a neutral line; the member sees why, instead of the message box.
    const notice = `${member.username} was muted in this room for 1 hour.`;
    await expect(rootPage.locator('.room-notice', { hasText: notice })).toBeVisible();
    await expect(memberPage.locator('.room-notice', { hasText: notice })).toBeVisible();
    await expect(memberPage.locator('#muted-notice')).toContainText(`You are muted in #${roomName}`);
    await expect(memberPage.locator('#muted-notice')).toContainText('Cool down, please');
    await expect(memberPage.locator('#composer-form')).toBeHidden();

    // Lifting it from the same card brings the message box back at once.
    await rootPage.locator('.message', { hasText: hello }).locator('.user-name', { hasText: member.username }).first().click();
    await rootPage.getByRole('button', { name: `Lift mute in #${roomName}` }).click();
    await expect(memberPage.locator('#composer-form')).toBeVisible();
    await expect(memberPage.locator('#muted-notice')).toBeHidden();
  } finally {
    await rootContext.close();
    await memberContext.close();
  }
});
