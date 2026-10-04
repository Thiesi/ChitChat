import { expect, test } from '@playwright/test';
import { SHRUG } from '../../public/assets/js/slash-commands.js';
import { attemptName, attemptText } from './support/attempt.js';

const baseURL = process.env.CHITCHAT_BASE_URL ?? 'http://127.0.0.1:8080';
const root = {
  username: 'RootE2E',
  password: 'Correct Horse Battery Staple 2026!',
};

async function login(page, account) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.locator('#login-username').fill(account.username);
  await page.locator('#login-password').fill(account.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
}

test('slash commands are listed, explained, and do what they say', async ({ page }) => {
  await login(page, root);
  const roomName = `Commands ${attemptName('Corner')}`;
  await page.locator('#new-room-button').click();
  const roomDialog = page.locator('#room-dialog');
  await roomDialog.locator('#room-key').fill(attemptName('commands-corner-e2e').toLowerCase());
  await roomDialog.locator('#room-name').fill(roomName);
  await roomDialog.getByRole('button', { name: 'Create room' }).click();
  await expect(page.locator('#room-title')).toHaveText(`# ${roomName}`);
  const input = page.locator('#composer-input');

  // "/" lists the commands; Tab picks the highlighted one.
  await input.click();
  await input.pressSequentially('/to');
  const list = page.getByRole('listbox', { name: 'Commands' });
  await expect(list.getByRole('option')).toHaveText([/\/topic text/]);
  await input.press('Tab');
  await expect(input).toHaveValue('/topic ');
  await expect(list).toBeHidden();

  // The room owner changes the info line.
  const topic = attemptText('Board games every Friday');
  await input.pressSequentially(topic);
  await input.press('Enter');
  await expect(page.locator('#room-info')).toContainText(topic);
  await expect(input).toHaveValue('');

  // /shrug sends the shrug, intact through formatting.
  const shrugText = attemptText('fair enough');
  await input.fill(`/shrug ${shrugText}`);
  await input.press('Enter');
  await expect(page.locator('.message-body', { hasText: shrugText })).toHaveText(`${shrugText} ${SHRUG}`);

  // /dm sends a direct message without leaving the room.
  await input.fill(`/dm MemberE2E ${attemptText('Hello from a slash command')}`);
  await input.press('Enter');
  await expect(page.locator('.toast', { hasText: 'Direct message sent to MemberE2E.' })).toBeVisible();
  await expect(page.locator('#room-title')).toHaveText(`# ${roomName}`);

  // Unknown commands are explained and the text stays for fixing.
  await input.fill('/nope');
  await input.press('Enter');
  await expect(page.locator('.toast', { hasText: 'is not a command here' })).toBeVisible();
  await expect(input).toHaveValue('/nope');

  // /help shows the commands and formatting.
  await input.fill('/help');
  await input.press('Enter');
  const help = page.getByRole('dialog', { name: 'Commands and formatting' });
  await expect(help).toBeVisible();
  await expect(help).toContainText('/dm name message');
  await expect(help).toContainText('*bold*');
  await help.getByRole('button', { name: 'Close' }).click();
  await expect(help).toBeHidden();
});
