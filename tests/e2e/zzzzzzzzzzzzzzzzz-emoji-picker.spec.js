import { expect, test } from '@playwright/test';
import { attemptText } from './support/attempt.js';

const member = {
  username: 'MemberE2E',
  password: 'Another Correct Horse Battery Staple 2026!',
};

async function login(page) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.locator('#login-username').fill(member.username);
  await page.locator('#login-password').fill(member.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
}

test('the composer emoji picker inserts emoji at the caret in rooms and direct messages', async ({ page }) => {
  await login(page);
  await page.locator('.room-button', { hasText: '# General E2E' }).click();
  await expect(page.locator('#room-title')).toHaveText('# General E2E');

  const input = page.locator('#composer-input');
  const text = attemptText('Ship it');
  await input.fill(text);
  const button = page.locator('#composer-wrap').getByRole('button', { name: 'Insert emoji' });
  await button.click();
  const dialog = page.getByRole('dialog', { name: 'Emoji' });
  await expect(dialog).toBeVisible();
  await expect(button).toHaveAttribute('aria-expanded', 'true');
  await expect(dialog.getByRole('searchbox', { name: 'Search emoji' })).toBeFocused();

  await page.keyboard.type('rocket');
  await dialog.getByRole('button', { name: 'rocket' }).click();
  await expect(dialog).toBeHidden();
  await expect(input).toHaveValue(`${text}🚀`);
  await expect(input).toBeFocused();

  await input.press('Enter');
  await expect(page.locator('.message-body', { hasText: `${text}🚀` })).toBeVisible();

  // Keyboard: the recently used emoji is first, and Escape returns focus.
  await button.click();
  await page.keyboard.press('ArrowDown');
  await expect(dialog.getByRole('group', { name: 'Recently used' }).getByRole('button', { name: 'rocket' })).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(button).toBeFocused();

  await page.goto('/messages.php');
  await expect(page.locator('#messages-shell')).toBeVisible();
  const conversation = page.locator('.conversation-button').first();
  await expect(conversation).toBeVisible();
  await conversation.click();
  const dmInput = page.locator('#dm-message-input');
  await expect(dmInput).toBeVisible();
  await dmInput.fill('Thanks');
  await page.locator('#dm-composer').getByRole('button', { name: 'Insert emoji' }).click();
  await page.keyboard.type('folded');
  await page.keyboard.press('Enter');
  await expect(dmInput).toHaveValue('Thanks🙏');
});
