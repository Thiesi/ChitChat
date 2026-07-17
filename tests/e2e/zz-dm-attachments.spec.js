import { expect, test } from '@playwright/test';

const baseURL = process.env.CHITCHAT_BASE_URL ?? 'http://127.0.0.1:8080';
const sender = {
  username: 'DMAttachSender',
  password: 'Direct Message Attachment Sender 2026!',
};
const recipient = {
  username: 'DMAttachRecipient',
  password: 'Direct Message Attachment Recipient 2026!',
};
const outsider = {
  username: 'DMAttachOutsider',
  password: 'Direct Message Attachment Outsider 2026!',
};

async function register(page, account) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.locator('#register-tab').click();
  await page.locator('#register-username').fill(account.username);
  await page.locator('#register-password').fill(account.password);
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
}

async function selectPeer(page, username) {
  await page.locator('#dm-user-search').fill(username);
  await page.getByRole('button', { name: 'Search' }).click();
  await page.locator('.dm-user-button', { hasText: username }).click();
  await expect(page.locator('#dm-peer-name')).toHaveText(username);
  await expect(page.locator('#dm-composer')).toBeVisible();
}

test('sends and authorizes direct-message attachments', async ({ browser }) => {
  const senderContext = await browser.newContext({ baseURL });
  const recipientContext = await browser.newContext({ baseURL });
  const outsiderContext = await browser.newContext({ baseURL });

  try {
    const senderChat = await senderContext.newPage();
    const recipientChat = await recipientContext.newPage();
    const outsiderChat = await outsiderContext.newPage();
    await register(senderChat, sender);
    await register(recipientChat, recipient);
    await register(outsiderChat, outsider);

    const senderMessages = await senderContext.newPage();
    const recipientMessages = await recipientContext.newPage();
    await senderMessages.goto('/messages.php');
    await recipientMessages.goto('/messages.php');
    await selectPeer(senderMessages, recipient.username);
    await selectPeer(recipientMessages, sender.username);

    await senderMessages.locator('#dm-attachment-input').setInputFiles({
      name: 'private-browser.txt',
      mimeType: 'text/plain',
      buffer: Buffer.from('direct-message attachment bytes\n'),
    });
    await senderMessages.locator('#dm-message-input').fill('Private attachment caption');
    await senderMessages.locator('#dm-send').click();
    await expect(senderMessages.locator('#toast-region')).toContainText('Attachment sent');

    const recipientDownload = recipientMessages.locator('.dm-attachment-download', {
      hasText: 'private-browser.txt',
    });
    await expect(recipientDownload).toBeVisible({ timeout: 20_000 });
    await expect(recipientMessages.locator('.dm-message-body', {
      hasText: 'Private attachment caption',
    })).toBeVisible();

    const href = await recipientDownload.getAttribute('href');
    expect(href).not.toBeNull();
    const participantResponse = await recipientContext.request.get(href);
    expect(participantResponse.status()).toBe(200);
    expect(await participantResponse.text()).toBe('direct-message attachment bytes\n');
    expect(participantResponse.headers()['content-disposition']).toContain('attachment');

    const outsiderResponse = await outsiderContext.request.get(href);
    expect(outsiderResponse.status()).toBe(404);
  } finally {
    await outsiderContext.close();
    await recipientContext.close();
    await senderContext.close();
  }
});
