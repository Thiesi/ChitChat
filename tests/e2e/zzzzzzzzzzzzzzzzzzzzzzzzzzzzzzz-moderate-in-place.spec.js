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

    // The card stayed open through the dialog (#300) and shows the new state; lifting
    // the mute from it brings the message box back at once.
    const card = rootPage.locator('#name-menu');
    await expect(card).toContainText(`${member.username} is muted in #${roomName}.`);
    await card.getByRole('button', { name: `Lift mute in #${roomName}` }).click();
    await expect(memberPage.locator('#composer-form')).toBeVisible();
    await expect(memberPage.locator('#muted-notice')).toBeHidden();
  } finally {
    await rootContext.close();
    await memberContext.close();
  }
});

test('acting on a report in the moderation queue closes the case', async ({ browser }) => {
  const rootContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });
  const roomName = `Reported ${attemptName('Hall')}`;

  try {
    const rootPage = await rootContext.newPage();
    await login(rootPage, root);
    await rootPage.locator('#new-room-button').click();
    const roomDialog = rootPage.locator('#room-dialog');
    await roomDialog.locator('#room-key').fill(attemptName('reported-hall-e2e').toLowerCase());
    await roomDialog.locator('#room-name').fill(roomName);
    await roomDialog.getByRole('button', { name: 'Create room' }).click();
    await expect(rootPage.locator('#room-title')).toHaveText(`# ${roomName}`);

    const memberPage = await memberContext.newPage();
    await login(memberPage, member);
    await openRoom(memberPage, roomName);
    await memberPage.locator('#join-button').click();
    const text = attemptText('Something worth a report');
    await memberPage.locator('#composer-input').fill(text);
    await memberPage.locator('#composer-input').press('Enter');

    // Someone reports it...
    const reported = rootPage.locator('.message', { hasText: text });
    await reported.getByRole('button', { name: 'Report', exact: true }).click();
    const reportDialog = rootPage.getByRole('dialog', { name: /Report/ });
    await reportDialog.locator('#message-report-category').selectOption('spam');
    await reportDialog.getByRole('button', { name: 'Report', exact: true }).click();
    await expect(rootPage.locator('#toast-region')).toContainText('Report submitted');

    // ...and the case itself offers Delete message, which also closes it.
    await rootPage.goto('/moderation.php');
    await rootPage.locator('#moderation-filter').selectOption('open');
    await rootPage.locator('.moderation-case-button', { hasText: `# ${roomName} · ${member.username}` }).click();
    const actions = rootPage.locator('#moderation-case-actions');
    await expect(actions.getByRole('button', { name: 'Ban author…' })).toBeVisible();
    await actions.getByRole('button', { name: 'Delete message' }).click();
    const confirm = rootPage.getByRole('dialog', { name: 'Delete the reported message?' });
    await confirm.getByRole('button', { name: 'Delete message' }).click();
    await expect(rootPage.locator('#moderation-closed')).toBeVisible();
    await expect(rootPage.locator('#moderation-closed-summary')).toContainText('content removed');
    await expect(actions).toBeHidden();

    await expect(memberPage.locator('.message-body', { hasText: text })).toHaveCount(0);
  } finally {
    await rootContext.close();
    await memberContext.close();
  }
});

// The transfer needs a fresh sign-in check; a recent one may still be valid.
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

// #300: the server's offer (#306) and the transfer endpoint (#277), end to end.
test('a room owner hands the room on from the profile card; the new owner is told', async ({ browser }) => {
  const rootContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });
  const roomName = `Handed ${attemptName('Hall')}`;

  try {
    const rootPage = await rootContext.newPage();
    await login(rootPage, root);
    await rootPage.locator('#new-room-button').click();
    const roomDialog = rootPage.locator('#room-dialog');
    await roomDialog.locator('#room-key').fill(attemptName('handed-hall-e2e').toLowerCase());
    await roomDialog.locator('#room-name').fill(roomName);
    await roomDialog.getByRole('button', { name: 'Create room' }).click();
    await expect(rootPage.locator('#room-title')).toHaveText(`# ${roomName}`);

    const memberPage = await memberContext.newPage();
    await login(memberPage, member);
    await openRoom(memberPage, roomName);
    await memberPage.locator('#join-button').click();
    const hello = attemptText('Happy to look after it');
    await memberPage.locator('#composer-input').fill(hello);
    await memberPage.locator('#composer-input').press('Enter');

    // The owner offers the room from the member's card, confirms, and passes the sign-in check.
    const memberName = () => rootPage.locator('.message', { hasText: hello }).locator('.user-name', { hasText: member.username }).first();
    await memberName().click();
    const card = rootPage.locator('#name-menu');
    await card.getByRole('button', { name: `Make owner of #${roomName}…` }).click();
    await rootPage.getByRole('dialog', { name: `Make ${member.username} the owner of #${roomName}?` }).getByRole('button', { name: 'Hand over' }).click();
    await passStepUp(rootPage, root.password);
    await expect(card).toContainText(`${member.username} owns #${roomName} now.`);

    // The new owner finds it in Activity.
    await memberPage.goto('/notifications.php');
    await expect(memberPage.locator('#privacy-notifications-loading')).toBeHidden();
    await expect(memberPage.locator('#privacy-notifications-shell')).toContainText(`Ownership of “${roomName}” was handed to you.`);

    // The previous owner's card offers nothing more: this person owns the room now.
    await rootPage.keyboard.press('Escape');
    await rootPage.reload();
    await openRoom(rootPage, roomName);
    await memberName().click();
    await expect(card).toContainText(member.username);
    await expect(card.getByRole('button', { name: `Make owner of #${roomName}…` })).toHaveCount(0);
  } finally {
    await rootContext.close();
    await memberContext.close();
  }
});
