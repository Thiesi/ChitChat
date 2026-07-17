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

async function stableMessage(page, selector, text) {
  const initial = page.locator(selector, { hasText: text }).last();
  await expect(initial).toBeVisible();
  const id = await initial.getAttribute('data-message-id');
  expect(id).not.toBeNull();
  return {
    id,
    locator: page.locator(`${selector}[data-message-id="${id}"]`),
  };
}

function acceptDialog(page, value = null) {
  page.once('dialog', async (dialog) => {
    if (dialog.type() === 'prompt') await dialog.accept(value ?? '');
    else await dialog.accept();
  });
}

async function reviewMessage(page, kind, messageId, reason) {
  await page.locator('#revision-review-kind').selectOption(kind);
  await page.locator('#revision-review-message-id').fill(String(messageId));
  await page.locator('#revision-review-reason').fill(reason);
  await page.getByRole('button', { name: 'Review revisions and write audit record' }).click();
  await expect(page.locator('#revision-review-results')).toBeVisible();
  await expect(page.locator('#revision-review-summary')).toContainText(String(messageId));
}

test('Super-Administrator reviews exact room and DM revision chains with a reason', async ({ browser }) => {
  const adminContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });

  try {
    const memberPage = await memberContext.newPage();
    await login(memberPage, member);
    await memberPage.locator('.room-button', { hasText: 'General E2E' }).click();
    await expect(memberPage.locator('#composer-wrap')).toBeVisible();
    await memberPage.locator('#composer-input').fill('Revision review room evidence');
    await memberPage.locator('#send-button').click();
    const roomMessage = await stableMessage(memberPage, 'article.message', 'Revision review room evidence');
    acceptDialog(memberPage, 'Revision review room evidence edited');
    await roomMessage.locator.getByRole('button', { name: 'Edit' }).click();
    await expect(roomMessage.locator.locator('.message-body')).toHaveText('Revision review room evidence edited');

    const adminLoginPage = await adminContext.newPage();
    await login(adminLoginPage, admin);
    await adminLoginPage.close();
    const reviewPage = await adminContext.newPage();
    await reviewPage.goto('/admin.php');
    await expect(reviewPage.locator('#admin-shell')).toBeVisible();
    await expect(reviewPage.locator('#revision-review-link')).toBeVisible();
    await reviewPage.locator('#revision-review-link').click();
    await expect(reviewPage.locator('#revision-review-shell')).toBeVisible();

    await reviewMessage(
      reviewPage,
      'room',
      roomMessage.id,
      'Reviewing the reported room-message edit history',
    );
    await expect(reviewPage.locator('#revision-review-context')).toContainText('General E2E');
    await expect(reviewPage.locator('.revision-card')).toHaveCount(1);
    const roomBodies = reviewPage.locator('.revision-card .revision-body');
    await expect(roomBodies.nth(0)).toHaveText('Revision review room evidence');
    await expect(roomBodies.nth(1)).toHaveText('Revision review room evidence edited');

    await memberPage.goto('/messages.php');
    await expect(memberPage.locator('#messages-shell')).toBeVisible();
    await memberPage.locator('#dm-user-search').fill(admin.username);
    await memberPage.getByRole('button', { name: 'Search' }).click();
    await memberPage.locator('.dm-user-button', { hasText: admin.username }).click();
    await expect(memberPage.locator('#dm-composer')).toBeVisible();
    await memberPage.locator('#dm-message-input').fill('Revision review private evidence');
    await memberPage.locator('#dm-send').click();
    const directMessage = await stableMessage(memberPage, 'article.dm-message', 'Revision review private evidence');
    acceptDialog(memberPage, 'Revision review private evidence edited');
    await directMessage.locator.getByRole('button', { name: 'Edit' }).click();
    await expect(directMessage.locator.locator('.dm-message-body')).toHaveText('Revision review private evidence edited');
    acceptDialog(memberPage);
    await directMessage.locator.getByRole('button', { name: 'Delete for everyone' }).click();
    await expect(directMessage.locator.locator('.dm-message-body')).toHaveText('Message deleted by sender.');

    await reviewMessage(
      reviewPage,
      'direct',
      directMessage.id,
      'Reviewing the disputed direct-message edit and deletion',
    );
    await expect(reviewPage.locator('#revision-review-context')).toContainText(member.username);
    await expect(reviewPage.locator('#revision-review-context')).toContainText(admin.username);
    await expect(reviewPage.locator('.revision-card')).toHaveCount(2);
    const editCard = reviewPage.locator('.revision-card').nth(0);
    await expect(editCard.locator('.revision-body').nth(0)).toHaveText('Revision review private evidence');
    await expect(editCard.locator('.revision-body').nth(1)).toHaveText('Revision review private evidence edited');
    const deleteCard = reviewPage.locator('.revision-card').nth(1);
    await expect(deleteCard).toContainText('Deletion');
    await expect(deleteCard.locator('.revision-body').nth(0)).toHaveText('Revision review private evidence edited');
    await expect(deleteCard.locator('.revision-body').nth(1)).toHaveText('Message deleted after this revision.');
  } finally {
    await memberContext.close();
    await adminContext.close();
  }
});
