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

async function send(page, text) {
  await page.locator('#composer-input').fill(text);
  await page.locator('#composer-input').press('Enter');
  await expect(page.locator('.message-body', { hasText: text })).toBeVisible();
}

test('ignoring someone collapses their room messages for you only', async ({ browser }) => {
  const rootContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });
  const roomName = `Ignore ${attemptName('Patio')}`;
  let ignoring = false;
  const memberPage = await memberContext.newPage();

  try {
    const rootPage = await rootContext.newPage();
    await login(rootPage, root);
    await rootPage.locator('#new-room-button').click();
    const roomDialog = rootPage.locator('#room-dialog');
    await roomDialog.locator('#room-key').fill(attemptName('ignore-patio-e2e').toLowerCase());
    await roomDialog.locator('#room-name').fill(roomName);
    await roomDialog.getByRole('button', { name: 'Create room' }).click();
    await expect(rootPage.locator('#room-title')).toHaveText(`# ${roomName}`);
    const before = attemptText('Before you ignored me');
    await send(rootPage, before);

    await login(memberPage, member);
    await openRoom(memberPage, roomName);
    await memberPage.locator('#join-button').click();
    await expect(memberPage.locator('.message-body', { hasText: before })).toBeVisible();

    // Ignore from the profile card behind the name.
    await memberPage.locator('.message .user-name', { hasText: root.username }).first().click();
    const ignore = memberPage.getByRole('button', { name: 'Ignore in rooms' });
    await ignore.click();
    ignoring = true;
    await expect(memberPage.getByRole('button', { name: 'Stop ignoring' })).toBeVisible();
    await memberPage.keyboard.press('Escape');
    const collapsed = memberPage.locator('.ignored-message', { hasText: `Message from ${root.username}` });
    await expect(collapsed).toHaveCount(1);
    await expect(memberPage.locator('.message-body', { hasText: before })).toHaveCount(0);

    // New messages arrive collapsed too, but stay visible to the sender.
    const after = attemptText('Still talking');
    await send(rootPage, after);
    await expect(collapsed).toHaveCount(2);
    await expect(rootPage.locator('.ignored-message')).toHaveCount(0);

    // One can be opened for the moment.
    await collapsed.first().getByRole('button', { name: /Show the message/ }).click();
    await expect(memberPage.locator('.message-body', { hasText: before })).toBeVisible();
  } finally {
    if (ignoring) {
      await memberPage.evaluate(async (rootName) => {
        const session = await (await fetch('/api/v1/session.php')).json();
        const users = await (await fetch(`/api/v1/direct-messages/users.php?search=${rootName}`)).json();
        const target = users.users.find((user) => user.username === rootName);
        await fetch('/api/v1/users/ignore.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': session.csrf_token },
          body: JSON.stringify({ user_id: target.id, ignored: false }),
        });
      }, root.username).catch(() => {});
    }
    await rootContext.close();
    await memberContext.close();
  }
});
