import { expect, test } from '@playwright/test';
import { attemptName, attemptText } from './support/attempt.js';

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
  const peerName = page.locator('#dm-peer-name');
  const selectedPeer = peerName.filter({ hasText: username });
  const conversation = page.locator('.conversation-button', { hasText: username }).first();

  await expect(selectedPeer.or(conversation).first()).toBeVisible({ timeout: 5_000 }).catch(() => {});
  if ((await peerName.textContent()) !== username && await conversation.isVisible()) {
    await conversation.click();
  }

  if ((await peerName.textContent()) !== username) {
    await page.locator('#dm-user-search').fill(username);
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    const result = page.locator('.dm-user-button', { hasText: username }).first();
    await expect(selectedPeer.or(result).first()).toBeVisible();
    if ((await peerName.textContent()) !== username) {
      await result.click();
    }
  }

  await expect(peerName).toHaveText(username);
  await expect(page.locator('#dm-composer')).toBeVisible();
}

function acceptDialog(page, value = null) {
  page.once('dialog', async (dialog) => {
    if (dialog.type() === 'prompt') await dialog.accept(value ?? '');
    else await dialog.accept();
  });
}

async function stableMessage(page, selector, text) {
  const initial = page.locator(selector, { hasText: text }).last();
  await expect(initial).toBeVisible({ timeout: 20_000 });
  const id = await initial.getAttribute('data-message-id');
  expect(id).not.toBeNull();
  return page.locator(`${selector}[data-message-id="${id}"]`);
}

async function sendRoomMessage(page, body) {
  const input = page.locator('#composer-input');
  await input.fill(body);
  const responsePromise = page.waitForResponse((response) => (
    response.url().endsWith('/api/v1/rooms/send.php')
    && response.request().method() === 'POST'
  ));
  await input.press('Enter');
  const response = await responsePromise;
  expect(response.status()).toBe(201);
}

test('authors edit and delete room and direct messages for everyone', async ({ browser }) => {
  const adminContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });
  const roomText = attemptText('Mutable room message');
  const editedRoomText = attemptText('Edited room message');
  const directText = attemptText('Mutable private message');
  const editedDirectText = attemptText('Edited private message');
  const attachmentText = attemptText('Mutable private attachment');
  const attachmentName = `${attemptName('mutable-private')}.txt`;

  try {
    const adminChat = await adminContext.newPage();
    const memberChat = await memberContext.newPage();
    await login(adminChat, admin);
    await login(memberChat, member);
    await selectRoom(adminChat, 'General E2E');
    await selectRoom(memberChat, 'General E2E');

    await sendRoomMessage(memberChat, roomText);
    const memberRoomMessage = await stableMessage(memberChat, 'article.message', roomText);
    const adminRoomMessage = adminChat.locator(
      `article.message[data-message-id="${await memberRoomMessage.getAttribute('data-message-id')}"]`,
    );
    await expect(adminRoomMessage).toContainText(roomText, { timeout: 20_000 });
    await expect(memberRoomMessage.getByRole('button', { name: 'Edit' })).toBeVisible();

    acceptDialog(memberChat, editedRoomText);
    await memberRoomMessage.getByRole('button', { name: 'Edit' }).click();
    await expect(memberRoomMessage.locator('.message-body')).toContainText(editedRoomText);
    await expect(adminRoomMessage).toContainText(editedRoomText, { timeout: 20_000 });
    await expect(memberRoomMessage.locator('.message-edited-indicator')).toHaveText('edited');

    acceptDialog(memberChat);
    await memberRoomMessage.getByRole('button', { name: 'Delete' }).click();
    await expect(memberRoomMessage.locator('.message-body')).toHaveText('Message deleted by its author.');
    await expect(adminRoomMessage).toContainText('Message deleted by its author.', { timeout: 20_000 });

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

    await memberMessages.locator('#dm-message-input').fill(directText);
    await memberMessages.locator('#dm-send').click();
    const memberDirectMessage = await stableMessage(memberMessages, 'article.dm-message', directText);
    const adminDirectMessage = adminMessages.locator(
      `article.dm-message[data-message-id="${await memberDirectMessage.getAttribute('data-message-id')}"]`,
    );
    await expect(adminDirectMessage).toContainText(directText, { timeout: 20_000 });
    await expect(memberDirectMessage.getByRole('button', { name: 'Edit' })).toBeVisible();

    acceptDialog(memberMessages, editedDirectText);
    await memberDirectMessage.getByRole('button', { name: 'Edit' }).click();
    await expect(memberDirectMessage.locator('.dm-message-body')).toHaveText(editedDirectText);
    await expect(adminDirectMessage).toContainText(editedDirectText, { timeout: 20_000 });

    acceptDialog(memberMessages);
    await memberDirectMessage.getByRole('button', { name: 'Delete for everyone' }).click();
    await expect(memberDirectMessage.locator('.dm-message-body')).toHaveText('Message deleted by sender.');
    await expect(adminDirectMessage).toContainText('Message deleted by sender.', { timeout: 20_000 });

    await memberMessages.locator('#dm-attachment-input').setInputFiles({
      name: attachmentName,
      mimeType: 'text/plain',
      buffer: Buffer.from('mutable private attachment\n'),
    });
    await memberMessages.locator('#dm-message-input').fill(attachmentText);
    await memberMessages.locator('#dm-send').click();
    const attachmentMessage = await stableMessage(memberMessages, 'article.dm-message', attachmentText);
    const download = attachmentMessage.locator('.dm-attachment-download', { hasText: attachmentName });
    await expect(download).toBeVisible({ timeout: 20_000 });
    const href = await download.getAttribute('href');
    expect(href).not.toBeNull();
    expect((await adminContext.request.get(href)).status()).toBe(200);

    acceptDialog(memberMessages);
    await attachmentMessage.getByRole('button', { name: 'Delete for everyone' }).click();
    await expect(attachmentMessage.locator('.dm-message-body')).toHaveText('Message deleted by sender.');
    await expect(attachmentMessage.locator('.attachment-card')).toHaveCount(0, { timeout: 20_000 });
    expect((await adminContext.request.get(href)).status()).toBe(410);
  } finally {
    await memberContext.close();
    await adminContext.close();
  }
});

import { forceOneRetry } from './support/force-retry.js';

forceOneRetry();
