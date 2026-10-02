import { expect, test } from '@playwright/test';
import { attemptAccount, attemptText } from './support/attempt.js';

const baseURL = process.env.CHITCHAT_BASE_URL ?? 'http://127.0.0.1:8080';
const root = {
  username: 'RootE2E',
  password: 'Correct Horse Battery Staple 2026!',
};
const member = {
  username: 'MemberE2E',
  password: 'Another Correct Horse Battery Staple 2026!',
};
const baseAuthor = {
  username: 'ReportedAuthorE2E',
  password: 'Reported Author Correct Horse Battery Staple 2026!',
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
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  await page.locator('.dm-user-button', { hasText: username }).click();
  await expect(page.locator('#dm-peer-name')).toHaveText(username);
}

async function submitReport(page, article, category, details) {
  await article.getByRole('button', { name: 'Report', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: /Report/ });
  await expect(dialog).toBeVisible();
  await dialog.locator('#message-report-category').selectOption(category);
  await dialog.locator('#message-report-details').fill(details);
  await dialog.getByRole('button', { name: 'Submit report' }).click();
  await expect(dialog).toBeHidden();
  await expect(page.locator('#toast-region')).toContainText('Report submitted');
}

test('participants submit exact-message reports and moderators review only submitted evidence', async ({ browser }) => {
  const author = attemptAccount(baseAuthor);
  const roomEvidence = attemptText('Moderation room evidence exact');
  const directEvidence = attemptText('Moderation direct evidence exact');
  const unrelatedContext = attemptText('Moderation unrelated private context');
  const authorContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });
  const rootContext = await browser.newContext({ baseURL });

  try {
    const authorPage = await authorContext.newPage();
    await register(authorPage, author);

    await authorPage.locator('.room-button', { hasText: '# General E2E' }).click();
    await expect(authorPage.locator('#room-title')).toHaveText('# General E2E');
    await authorPage.locator('#join-button').click();
    await expect(authorPage.locator('#composer-wrap')).toBeVisible();
    await authorPage.locator('#composer-input').fill(roomEvidence);
    await authorPage.locator('#send-button').click();
    await expect(authorPage.locator('.message-body', { hasText: roomEvidence })).toBeVisible();

    await selectPeer(authorPage, member.username);
    await authorPage.locator('#dm-message-input').fill(directEvidence);
    await authorPage.locator('#dm-send').click();
    await expect(authorPage.locator('.dm-message-body', { hasText: directEvidence })).toBeVisible();
    await authorPage.locator('#dm-message-input').fill(unrelatedContext);
    await authorPage.locator('#dm-send').click();
    await expect(authorPage.locator('.dm-message-body', { hasText: unrelatedContext })).toBeVisible();
    await expect(
      authorPage.locator('.dm-message', { hasText: directEvidence }).getByRole('button', { name: 'Report' }),
    ).toHaveCount(0);

    const memberPage = await memberContext.newPage();
    await login(memberPage, member);
    await memberPage.locator('.room-button', { hasText: '# General E2E' }).click();
    const roomMessage = memberPage.locator('.message', { hasText: roomEvidence });
    await expect(roomMessage).toBeVisible();
    await expect(roomMessage.getByRole('button', { name: 'Report', exact: true })).toBeVisible();
    await submitReport(memberPage, roomMessage, 'harassment', 'Room report details stay out of audit metadata.');

    await selectPeer(memberPage, author.username);
    const directMessage = memberPage.locator('.dm-message', { hasText: directEvidence });
    await expect(directMessage).toBeVisible();
    await expect(directMessage.getByRole('button', { name: 'Report', exact: true })).toBeVisible();
    await submitReport(memberPage, directMessage, 'threats', 'Review this exact direct message only.');

    const rootPage = await rootContext.newPage();
    await login(rootPage, root);
    await rootPage.goto('/moderation.php');
    await expect(rootPage.locator('#moderation-shell')).toBeVisible();
    await expect(rootPage.locator('.moderation-notice')).toContainText('does not grant access to surrounding conversation history');

    const directCase = rootPage.locator('.moderation-case-button', {
      hasText: `Direct message · ${author.username}`,
    });
    await expect(directCase).toBeVisible();
    await directCase.click();
    await expect(rootPage.locator('.moderation-evidence', { hasText: directEvidence })).toBeVisible();
    await expect(rootPage.locator('body')).not.toContainText(unrelatedContext);
    await expect(rootPage.locator('.moderation-report-card')).toContainText('Review this exact direct message only.');

    await rootPage.locator('#moderation-claim').click();
    await expect(rootPage.locator('#toast-region')).toContainText('Case claimed');
    await expect(rootPage.locator('#moderation-case-meta')).toContainText('In review');
    await rootPage.locator('#moderation-resolution-code').selectOption('user_warned');
    await rootPage.locator('#moderation-resolution-note').fill('Handled through the ordinary account-moderation workflow.');
    await rootPage.locator('#moderation-resolve').click();
    await expect(rootPage.locator('#moderation-closed')).toBeVisible();
    await expect(rootPage.locator('#moderation-closed-summary')).toContainText('user warned');

    await rootPage.locator('#moderation-filter').selectOption('open');
    const roomCase = rootPage.locator('.moderation-case-button', {
      hasText: `# General E2E · ${author.username}`,
    });
    await expect(roomCase).toBeVisible();
    await roomCase.click();
    await expect(rootPage.locator('.moderation-evidence', { hasText: roomEvidence })).toBeVisible();
    await expect(rootPage.locator('.moderation-report-card')).toContainText('Room report details stay out of audit metadata.');
  } finally {
    await rootContext.close();
    await memberContext.close();
    await authorContext.close();
  }
});

import { forceOneRetry } from './support/force-retry.js';

forceOneRetry();
