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

async function login(page, account) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.locator('#login-username').fill(account.username);
  await page.locator('#login-password').fill(account.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
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

async function stableMessage(page, selector, text) {
  const initial = page.locator(selector, { hasText: text }).last();
  await expect(initial).toBeVisible({ timeout: 20_000 });
  const id = await initial.getAttribute('data-message-id');
  expect(id).not.toBeNull();
  return page.locator(`${selector}[data-message-id="${id}"]`);
}

async function addReaction(messageLocator, emoji) {
  await messageLocator.getByRole('button', { name: 'Add reaction' }).click();
  await messageLocator.getByRole('button', { name: `React with ${emoji}` }).click();
}

function reactionPill(messageLocator, emoji) {
  return messageLocator.getByRole('button', { name: new RegExp(`^${emoji} reaction,`, 'u') });
}

test('participants react to a room message, with aggregation, idempotent toggling, and realtime delivery', async ({ browser }) => {
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

    await rootPage.locator('#composer-input').fill('Reactable room message');
    await rootPage.locator('#send-button').click();
    const rootView = await stableMessage(rootPage, 'article.message', 'Reactable room message');
    await expect(memberPage.locator('article.message', { hasText: 'Reactable room message' }))
      .toBeVisible({ timeout: 20_000 });
    const memberView = await stableMessage(memberPage, 'article.message', 'Reactable room message');

    // Root reacts first.
    await addReaction(rootView, '👍');
    const rootPill = reactionPill(rootView, '👍');
    await expect(rootPill).toBeVisible();
    await expect(rootPill).toHaveAttribute('aria-pressed', 'true');
    await expect(rootPill.locator('.reaction-button-count')).toHaveText('1');

    // The member sees the same reaction appear via SSE. It is not their own,
    // so their copy of the same shared broadcast payload must render inactive.
    const memberPill = reactionPill(memberView, '👍');
    await expect(memberPill).toBeVisible({ timeout: 20_000 });
    await expect(memberPill).toHaveAttribute('aria-pressed', 'false');

    // The member reacts with the same emoji; the pill aggregates rather than duplicating.
    await memberPill.click();
    await expect(memberPill).toHaveAttribute('aria-pressed', 'true');
    await expect(memberPill.locator('.reaction-button-count')).toHaveText('2');
    await expect(rootPill.locator('.reaction-button-count')).toHaveText('2', { timeout: 20_000 });
    await expect(rootPill).toHaveAttribute('aria-pressed', 'true');

    // Root removes their own reaction; the pill survives with only the member's.
    await rootPill.click();
    await expect(rootPill).toHaveAttribute('aria-pressed', 'false');
    await expect(rootPill.locator('.reaction-button-count')).toHaveText('1');
    await expect(memberPill.locator('.reaction-button-count')).toHaveText('1', { timeout: 20_000 });
    await expect(memberPill).toHaveAttribute('aria-pressed', 'true');

    // The member removes the last reaction; the pill disappears for everyone,
    // since an emoji with zero reactors is never rendered.
    await memberPill.click();
    await expect(reactionPill(memberView, '👍')).toHaveCount(0);
    await expect(reactionPill(rootView, '👍')).toHaveCount(0, { timeout: 20_000 });
  } finally {
    await memberContext.close();
    await rootContext.close();
  }
});

test('participants react to a direct message, with idempotent toggling and realtime delivery to the other participant', async ({ browser }) => {
  const rootContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });

  try {
    const rootPage = await rootContext.newPage();
    const memberPage = await memberContext.newPage();
    await login(rootPage, root);
    await login(memberPage, member);

    await rootPage.goto('/messages.php');
    await expect(rootPage.locator('#messages-shell')).toBeVisible();
    await memberPage.goto('/messages.php');
    await expect(memberPage.locator('#messages-shell')).toBeVisible();
    await selectPeer(rootPage, member.username);
    await selectPeer(memberPage, root.username);

    await rootPage.locator('#dm-message-input').fill('Reactable private message');
    await rootPage.locator('#dm-send').click();
    const rootView = await stableMessage(rootPage, 'article.dm-message', 'Reactable private message');
    await expect(memberPage.locator('article.dm-message', { hasText: 'Reactable private message' }))
      .toBeVisible({ timeout: 20_000 });
    const memberView = await stableMessage(memberPage, 'article.dm-message', 'Reactable private message');

    // The recipient reacts. Direct-message reaction events are published as
    // two separately targeted payloads, so each side's own perspective must
    // be correct without either side trusting the other's copy.
    await addReaction(memberView, '❤️');
    const memberPill = reactionPill(memberView, '❤️');
    await expect(memberPill).toBeVisible();
    await expect(memberPill).toHaveAttribute('aria-pressed', 'true');
    await expect(memberPill.locator('.reaction-button-count')).toHaveText('1');

    const rootPill = reactionPill(rootView, '❤️');
    await expect(rootPill).toBeVisible({ timeout: 20_000 });
    await expect(rootPill).toHaveAttribute('aria-pressed', 'false');
    await expect(rootPill.locator('.reaction-button-count')).toHaveText('1');

    await memberPill.click();
    await expect(reactionPill(memberView, '❤️')).toHaveCount(0);
    await expect(reactionPill(rootView, '❤️')).toHaveCount(0, { timeout: 20_000 });
  } finally {
    await memberContext.close();
    await rootContext.close();
  }
});
