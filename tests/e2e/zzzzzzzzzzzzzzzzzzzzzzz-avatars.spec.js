import { expect, test } from '@playwright/test';
import { fileURLToPath } from 'node:url';

const sample = fileURLToPath(new URL('./fixtures/avatar-sample.png', import.meta.url));
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

test('a cropped profile picture replaces initials in chat and on the profile card', async ({ page }) => {
  await login(page);
  await page.goto('/account.php');
  await page.locator('#avatar-file').setInputFiles(sample);
  const crop = page.getByRole('dialog', { name: 'Crop your picture' });
  await expect(crop).toBeVisible();
  await page.locator('#avatar-crop-zoom').fill('1.5');
  await crop.getByRole('button', { name: 'Save picture' }).click();
  await expect(page.locator('#avatar-status')).toHaveText('Picture saved.');

  try {
    // The server stores its own 256 x 256 WebP, whatever the browser sent.
    const response = await page.request.get(`/api/v1/avatars/show.php?user_id=${await userId(page)}`);
    expect(response.headers()['content-type']).toBe('image/webp');

    await page.goto('/');
    await page.locator('.room-button', { hasText: '# General E2E' }).click();
    await expect(page.locator('#user-menu-button')).toHaveClass(/has-photo/);
    const ownMessage = page.locator('.message', { has: page.locator('.message-author', { hasText: member.username }) }).first();
    await expect(ownMessage.locator('.message-avatar')).toHaveClass(/has-photo/);

    await ownMessage.locator('.message-author').click();
    const card = page.locator('#name-menu');
    await expect(card).toContainText('This is you');
    await expect(card).toContainText('Member since');
    await expect(card.locator('.profile-avatar')).toHaveClass(/has-photo/);
  } finally {
    // Other specs expect initials for this shared account.
    await page.goto('/account.php');
    await page.getByRole('button', { name: 'Remove picture' }).click();
    await expect(page.locator('#avatar-status')).toContainText('Picture removed');
  }
});

async function userId(page) {
  const session = await (await page.request.get('/api/v1/session.php')).json();
  return session.user.id;
}
