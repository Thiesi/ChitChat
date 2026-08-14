import { expect, test } from '@playwright/test';

const baseURL = process.env.CHITCHAT_BASE_URL ?? 'http://127.0.0.1:8080';
const root = {
  username: 'RootE2E',
  password: 'Correct Horse Battery Staple 2026!',
};
const member = {
  username: 'MemberE2E',
  password: 'Another Correct Horse Battery Staple 2026!',
};
const peer = {
  username: 'MentionPeerE2E',
  password: 'Mention Peer Correct Horse Battery Staple 2026!',
};

async function login(page, account) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.locator('#login-username').fill(account.username);
  await page.locator('#login-password').fill(account.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
}

async function register(page, account) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.getByRole('tab', { name: 'Register' }).click();
  await page.locator('#register-username').fill(account.username);
  await page.locator('#register-password').fill(account.password);
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
}

async function stableMessage(page, selector, text) {
  const initial = page.locator(selector, { hasText: text }).last();
  await expect(initial).toBeVisible({ timeout: 20_000 });
  const id = await initial.getAttribute('data-message-id');
  expect(id).not.toBeNull();
  return page.locator(`${selector}[data-message-id="${id}"]`);
}

test('participants reply to and mention each other, with a working notification deep link', async ({ browser }) => {
  const rootContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });

  try {
    const rootPage = await rootContext.newPage();
    const memberPage = await memberContext.newPage();
    await login(rootPage, root);
    await login(memberPage, member);

    await rootPage.locator('.room-button', { hasText: '# General E2E' }).click();
    await expect(rootPage.locator('#room-title')).toHaveText('# General E2E');
    await memberPage.locator('.room-button', { hasText: '# General E2E' }).click();
    await expect(memberPage.locator('#room-title')).toHaveText('# General E2E');

    await rootPage.locator('#composer-input').fill('Original room message awaiting a reply');
    await rootPage.locator('#send-button').click();
    const originalMessage = await stableMessage(rootPage, 'article.message', 'Original room message awaiting a reply');

    await expect(memberPage.locator('article.message', { hasText: 'Original room message awaiting a reply' }))
      .toBeVisible({ timeout: 20_000 });
    const memberViewOfOriginal = await stableMessage(memberPage, 'article.message', 'Original room message awaiting a reply');
    await memberViewOfOriginal.getByRole('button', { name: 'Reply' }).click();
    await expect(memberPage.locator('#reply-banner')).toBeVisible();
    await expect(memberPage.locator('#reply-banner-text')).toContainText('Replying to RootE2E');

    await memberPage.locator('#composer-input').fill('@RootE2E thanks for the context');
    await memberPage.locator('#send-button').click();
    await expect(memberPage.locator('#reply-banner')).toBeHidden();

    const memberReply = await stableMessage(memberPage, 'article.message', 'thanks for the context');
    await expect(memberReply.locator('.reply-preview-author')).toHaveText('RootE2E');
    await expect(memberReply.locator('.reply-preview-excerpt')).toContainText('Original room message awaiting a reply');
    await expect(memberReply.locator('.message-body .mention')).toHaveText('@RootE2E');

    await expect(rootPage.locator('article.message', { hasText: 'thanks for the context' }))
      .toBeVisible({ timeout: 20_000 });
    const rootViewOfReply = await stableMessage(rootPage, 'article.message', 'thanks for the context');
    await rootViewOfReply.locator('.reply-preview').click();
    await expect(originalMessage).toHaveClass(/search-result-target/);

    await rootPage.goto('/notifications.php');
    await expect(rootPage.locator('#privacy-notifications-shell')).toBeVisible();
    const mentionNotification = rootPage.locator('.privacy-notification', { hasText: 'You were mentioned' });
    await expect(mentionNotification).toBeVisible();
    await expect(mentionNotification).toContainText('MemberE2E mentioned you in “General E2E”.');
    await mentionNotification.getByRole('link', { name: 'View message' }).click();
    await expect(rootPage.locator('#room-title')).toHaveText('# General E2E');
    await expect(rootPage.locator('article.message.search-result-target', { hasText: 'thanks for the context' }))
      .toBeVisible();
  } finally {
    await memberContext.close();
    await rootContext.close();
  }
});

test('direct-message mentions only resolve the recipient and notify them', async ({ browser }) => {
  const memberContext = await browser.newContext({ baseURL });
  const peerContext = await browser.newContext({ baseURL });

  try {
    const memberPage = await memberContext.newPage();
    await login(memberPage, member);

    const peerPage = await peerContext.newPage();
    await register(peerPage, peer);

    await memberPage.goto('/messages.php');
    await expect(memberPage.locator('#messages-shell')).toBeVisible();
    await memberPage.locator('#dm-user-search').fill(peer.username);
    await memberPage.getByRole('button', { name: 'Search', exact: true }).click();
    await memberPage.locator('.dm-user-button', { hasText: peer.username }).click();
    await expect(memberPage.locator('#dm-peer-name')).toHaveText(peer.username);

    await memberPage.locator('#dm-message-input').fill(`Hi @${peer.username}, and hi @NotARealUserE2E too`);
    await memberPage.locator('#dm-send').click();
    const sentMessage = await stableMessage(memberPage, 'article.dm-message', `Hi @${peer.username}`);
    await expect(sentMessage.locator('.mention')).toHaveText(`@${peer.username}`);
    await expect(sentMessage.locator('.dm-message-body')).toContainText('@NotARealUserE2E');

    await peerPage.goto('/notifications.php');
    await expect(peerPage.locator('#privacy-notifications-shell')).toBeVisible();
    const notification = peerPage.locator('.privacy-notification', { hasText: 'You were mentioned' });
    await expect(notification).toContainText(`${member.username} mentioned you in a direct message.`);
  } finally {
    await peerContext.close();
    await memberContext.close();
  }
});
