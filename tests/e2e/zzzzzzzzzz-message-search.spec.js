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
  username: 'SearchPeerE2E',
  password: 'Search Peer Correct Horse Battery Staple 2026!',
};
const outsider = {
  username: 'SearchOutsiderE2E',
  password: 'Search Outsider Correct Horse Battery Staple 2026!',
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

async function selectPeer(page, username) {
  await page.goto('/messages.php');
  await expect(page.locator('#messages-shell')).toBeVisible();
  await page.locator('#dm-user-search').fill(username);
  await page.getByRole('button', { name: 'Search' }).click();
  await page.locator('.dm-user-button', { hasText: username }).click();
  await expect(page.locator('#dm-peer-name')).toHaveText(username);
  await expect(page.locator('#dm-composer')).toBeVisible();
}

test('participants search only currently visible room and direct-message bodies', async ({ browser }) => {
  const rootContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });
  const peerContext = await browser.newContext({ baseURL });
  const outsiderContext = await browser.newContext({ baseURL });

  try {
    const rootPage = await rootContext.newPage();
    await login(rootPage, root);
    await rootPage.locator('#new-room-button').click();
    const roomDialog = rootPage.locator('#room-dialog');
    await roomDialog.locator('#room-key').fill('searchprivatee2e');
    await roomDialog.locator('#room-name').fill('Search Private E2E');
    await roomDialog.locator('#room-visibility').selectOption('private');
    await roomDialog.getByRole('button', { name: 'Create room' }).click();
    await expect(rootPage.locator('#room-title')).toHaveText('# Search Private E2E');
    await rootPage.locator('#composer-input').fill('Quartzsearch hidden private evidence');
    await rootPage.locator('#send-button').click();
    await expect(rootPage.locator('.message-body', { hasText: 'Quartzsearch hidden private evidence' })).toBeVisible();

    const outsiderPage = await outsiderContext.newPage();
    await register(outsiderPage, outsider);

    const peerPage = await peerContext.newPage();
    await register(peerPage, peer);
    await selectPeer(peerPage, outsider.username);
    await peerPage.locator('#dm-message-input').fill('Quartzsearch unrelated direct evidence');
    await peerPage.locator('#dm-send').click();
    await expect(peerPage.locator('.dm-message-body', { hasText: 'Quartzsearch unrelated direct evidence' })).toBeVisible();

    const memberPage = await memberContext.newPage();
    await login(memberPage, member);
    await memberPage.locator('.room-button', { hasText: '# General E2E' }).click();
    await expect(memberPage.locator('#room-title')).toHaveText('# General E2E');
    await memberPage.locator('#composer-input').fill('Quartzsearch public room evidence');
    await memberPage.locator('#send-button').click();
    await expect(memberPage.locator('.message-body', { hasText: 'Quartzsearch public room evidence' })).toBeVisible();

    await selectPeer(memberPage, peer.username);
    await memberPage.locator('#dm-message-input').fill('Quartzsearch participant direct evidence');
    await memberPage.locator('#dm-send').click();
    await expect(memberPage.locator('.dm-message-body', { hasText: 'Quartzsearch participant direct evidence' })).toBeVisible();

    await memberPage.goto('/search.php');
    await expect(memberPage.locator('#message-search-shell')).toBeVisible();
    await expect(memberPage.locator('.message-search-notice')).toContainText('retained revision bodies are deliberately excluded');
    await memberPage.locator('#message-search-query').fill('quartzsearch');
    await memberPage.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(memberPage.locator('#message-search-status')).toContainText('2 results shown');
    expect(memberPage.url()).not.toContain('quartzsearch');
    await expect(memberPage.locator('.message-search-excerpt', { hasText: 'Quartzsearch public room evidence' })).toBeVisible();
    await expect(memberPage.locator('.message-search-excerpt', { hasText: 'Quartzsearch participant direct evidence' })).toBeVisible();
    await expect(memberPage.locator('.message-search-excerpt', { hasText: 'Quartzsearch hidden private evidence' })).toHaveCount(0);
    await expect(memberPage.locator('.message-search-excerpt', { hasText: 'Quartzsearch unrelated direct evidence' })).toHaveCount(0);

    await memberPage.getByRole('link', { name: '# General E2E' }).click();
    await expect(memberPage.locator('#room-title')).toHaveText('# General E2E');
    await expect(memberPage.locator('.message.search-result-target', { hasText: 'Quartzsearch public room evidence' })).toBeVisible();

    await memberPage.goto('/search.php?scope=direct');
    await expect(memberPage.locator('#message-search-shell')).toBeVisible();
    await memberPage.locator('#message-search-query').fill('quartzsearch');
    await memberPage.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(memberPage.locator('#message-search-status')).toContainText('1 result shown');
    await memberPage.getByRole('link', { name: `Conversation with ${peer.username}` }).click();
    await expect(memberPage.locator('#dm-peer-name')).toHaveText(peer.username);
    await expect(memberPage.locator('.dm-message.search-result-target', { hasText: 'Quartzsearch participant direct evidence' })).toBeVisible();
  } finally {
    await outsiderContext.close();
    await peerContext.close();
    await memberContext.close();
    await rootContext.close();
  }
});
