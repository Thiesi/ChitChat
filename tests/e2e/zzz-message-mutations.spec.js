import { expect, test } from '@playwright/test';

const baseURL = process.env.CHITCHAT_BASE_URL ?? 'http://127.0.0.1:8080';
const admin = {
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
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
  await expect(page.locator('#current-user')).toHaveText(account.username);
}

async function selectRoom(page, name) {
  await page.locator('.room-button', { hasText: name }).click();
  await expect(page.locator('#room-title')).toContainText(name);
  await expect(page.locator('#composer-wrap')).toBeVisible();
}

async function selectPeer(page, username) {
  await page.locator('#dm-user-search').fill(username);
  await page.getByRole('button', { name: 'Search' }).click();
  await page.locator('.dm-user-button', { hasText: username }).click();
  await expect(page.locator('#dm-peer-name')).toHaveText(username);
  await expect(page.locator('#dm-composer')).toBeVisible();
}

async function acceptDialog(page, value = null) {
  page.once('dialog', async (dialog) => {
    if (dialog.type() === 'prompt') await dialog.accept(value ?? '');
    else await dialog.accept();
  });
}

test('authors edit and delete room and direct messages for everyone', async ({ browser }) => {
  const adminContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });

  try {
    const adminChat = await adminContext.newPage();
    const memberChat = await memberContext.newPage();
    await login(adminChat, admin);
    await login(memberChat, member);
    await selectRoom(adminChat, 'General E2E');
    await selectRoom(memberChat, 'General E2E');

    await memberChat.locator('#composer-input').fill('Mutable room message');
    await memberChat.locator('#send-button').click();
    const memberRoomMessage = memberChat.locator('article.message', { hasText: 'Mutable room message' });
    const adminRoomMessage = adminChat.locator('article.message', { hasText: 'Mutable room message' });
    await expect(memberRoomMessage).toBeVisible();
    await expect(adminRoomMessage).toBeVisible();
    await expect(memberRoomMessage.getByRole('button', { name: 'Edit' })).toBeVisible();

    await acceptDialog(memberChat, 'Edited room message');
    await memberRoomMessage.getByRole('button', { name: 'Edit' }).click();
    await expect(memberChat.locator('article.message', { hasText: 'Edited room message' })).toBeVisible();
    await expect(adminChat.locator('article.message', { hasText: 'Edited room message' })).toBeVisible({ timeout: 20_000 });
    await expect(memberChat.locator('article.message', { hasText: 'Edited room message' }).locator('.message-edited-indicator')).toHaveText('edited');

    const editedMemberRoomMessage = memberChat.locator('article.message', { hasText: 'Edited room message' });
    await acceptDialog(memberChat);
    await editedMemberRoomMessage.getByRole('button', { name: 'Delete' }).click();
    await expect(editedMemberRoomMessage.locator('.message-body')).toHaveText('Message deleted by its author.');
    await expect(adminChat.locator('article.message', { hasText: 'Message deleted by its author.' })).toBeVisible({ timeout: 20_000 });

    await adminChat.close();
    await memberChat.close();

    const adminMessages = await adminContext.newPage();
    const memberMessages = await memberContext.newPage();
    await adminMessages.goto('/messages.php');
    await memberMessages.goto('/messages.php');
    await expect(adminMessages.locator('#messages-shell')).toBeVisible();
    await expect(memberMessages.locator('#messages-shell')).toBeVisible();
    await selectPeer(adminMessages, member.username);
    await selectPeer(memberMessages, admin.username);

    await memberMessages.locator('#dm-message-input').fill('Mutable private message');
    await memberMessages.locator('#dm-send').click();
    const memberDirectMessage = memberMessages.locator('article.dm-message', { hasText: 'Mutable private message' });
    await expect(memberDirectMessage).toBeVisible();
    await expect(adminMessages.locator('article.dm-message', { hasText: 'Mutable private message' })).toBeVisible();
    await expect(memberDirectMessage.getByRole('button', { name: 'Edit' })).toBeVisible();

    await acceptDialog(memberMessages, 'Edited private message');
    await memberDirectMessage.getByRole('button', { name: 'Edit' }).click();
    await expect(memberMessages.locator('article.dm-message', { hasText: 'Edited private message' })).toBeVisible();
    await expect(adminMessages.locator('article.dm-message', { hasText: 'Edited private message' })).toBeVisible({ timeout: 20_000 });

    const editedDirectMessage = memberMessages.locator('article.dm-message', { hasText: 'Edited private message' });
    await acceptDialog(memberMessages);
    await editedDirectMessage.getByRole('button', { name: 'Delete for everyone' }).click();
    await expect(editedDirectMessage.locator('.dm-message-body')).toHaveText('Message deleted by sender.');
    await expect(adminMessages.locator('article.dm-message', { hasText: 'Message deleted by sender.' })).toBeVisible({ timeout: 20_000 });

    await memberMessages.locator('#dm-attachment-input').setInputFiles({
      name: 'mutable-private.txt',
      mimeType: 'text/plain',
      buffer: Buffer.from('mutable private attachment\n'),
    });
    await memberMessages.locator('#dm-message-input').fill('Mutable private attachment');
    await memberMessages.locator('#dm-send').click();
    const attachmentMessage = memberMessages.locator('article.dm-message', { hasText: 'Mutable private attachment' });
    const download = attachmentMessage.locator('.dm-attachment-download', { hasText: 'mutable-private.txt' });
    await expect(download).toBeVisible({ timeout: 20_000 });
    const href = await download.getAttribute('href');
    expect(href).not.toBeNull();
    expect((await adminContext.request.get(href)).status()).toBe(200);

    await acceptDialog(memberMessages);
    await attachmentMessage.getByRole('button', { name: 'Delete for everyone' }).click();
    await expect(attachmentMessage.locator('.dm-message-body')).toHaveText('Message deleted by sender.');
    await expect(attachmentMessage.locator('.dm-attachment-card')).toHaveCount(0);
    expect((await adminContext.request.get(href)).status()).toBe(410);
  } finally {
    await memberContext.close();
    await adminContext.close();
  }
});
