import { expect, test } from '@playwright/test';
import { fileURLToPath } from 'node:url';
import { attemptText } from './support/attempt.js';

const baseURL = process.env.CHITCHAT_BASE_URL ?? 'http://127.0.0.1:8080';
const sample = fileURLToPath(new URL('./fixtures/avatar-sample.png', import.meta.url));
const member = {
  username: 'MemberE2E',
  password: 'Another Correct Horse Battery Staple 2026!',
};
const root = {
  username: 'RootE2E',
  password: 'Correct Horse Battery Staple 2026!',
};

async function login(page, account = member) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.locator('#login-username').fill(account.username);
  await page.locator('#login-password').fill(account.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
}

test('a cropped profile picture replaces initials in chat and on the profile card', async ({ page, browser }) => {
  await login(page);
  await page.goto('/account.php#profile');
  await page.locator('#avatar-file').setInputFiles(sample);
  const crop = page.getByRole('dialog', { name: 'Crop your picture' });
  await expect(crop).toBeVisible();
  await page.locator('#avatar-crop-zoom').fill('1.5');
  await crop.getByRole('button', { name: 'Save picture' }).click();
  await expect(page.locator('#avatar-status')).toHaveText('Picture saved.');
  // Your own picture shows on You, next to your name (the account menu went in #168, S2b).
  await expect(page.locator('#you-avatar')).toHaveClass(/has-photo/);

  const rootContext = await browser.newContext({ baseURL });
  try {
    // The server stores its own 256 x 256 WebP, whatever the browser sent.
    const response = await page.request.get(`/api/v1/avatars/show.php?user_id=${await userId(page)}`);
    expect(response.headers()['content-type']).toBe('image/webp');

    await page.goto('/');
    await page.locator('.room-button', { hasText: '# General E2E' }).click();
    const text = attemptText('Avatar check');
    await page.locator('#composer-input').fill(text);
    await page.locator('#composer-input').press('Enter');
    await expect(page.locator('.message-body', { hasText: text })).toBeVisible();

    // Since S1c (#166) your own messages carry no pebble, so the picture in
    // chat is checked where it shows: on your message as someone else sees it.
    const rootPage = await rootContext.newPage();
    await login(rootPage, root);
    await rootPage.locator('.room-button', { hasText: '# General E2E' }).click();
    const seen = rootPage.locator('.message', { has: rootPage.locator('.message-body', { hasText: text }) });
    await expect(seen.locator('.message-avatar')).toHaveClass(/has-photo/);

    await seen.locator('.message-author').click();
    const card = rootPage.locator('#name-menu');
    await expect(card).toContainText(member.username);
    await expect(card).toContainText('Member since');
    await expect(card.locator('.profile-avatar')).toHaveClass(/has-photo/);
  } finally {
    await rootContext.close();
    // Other specs expect initials for this shared account.
    await page.goto('/account.php#profile');
    await page.getByRole('button', { name: 'Remove picture' }).click();
    await expect(page.locator('#avatar-status')).toContainText('Picture removed');
  }
});

async function userId(page) {
  const session = await (await page.request.get('/api/v1/session.php')).json();
  return session.user.id;
}
