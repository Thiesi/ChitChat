import { expect, test } from '@playwright/test';
import { attemptName, attemptText } from './support/attempt.js';
import { openRoom } from './support/rooms.js';

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

// Operational settings need privileged step-up; a recent one may still be valid.
async function setGuestAccess(page, enabled) {
  await page.goto('/admin-settings.php');
  await expect(page.locator('#settings-shell')).toBeVisible();
  await page.locator('#guest-access-enabled').selectOption(enabled ? '1' : '0');
  page.once('dialog', (dialog) => dialog.accept());
  // The save may first ask for step-up and then retry, so wait for the one that succeeds.
  const saved = page.waitForResponse((response) => response.url().includes('/api/v1/admin/settings/update.php') && response.ok());
  await page.locator('#save-settings').click();
  const stepUp = page.locator('.step-up-dialog');
  try {
    await stepUp.waitFor({ state: 'visible', timeout: 3_000 });
    await stepUp.locator('#step-up-password').fill(root.password);
    await stepUp.getByRole('button', { name: 'Verify password' }).click();
    await expect(stepUp).toBeHidden();
  } catch {
    // No step-up prompt was needed.
  }
  await saved;
  await page.reload();
  await expect(page.locator('#guest-access-enabled')).toHaveValue(enabled ? '1' : '0');
}

async function createRoom(page, key, name, guests) {
  await page.locator('#new-room-button').click();
  const dialog = page.locator('#room-dialog');
  await dialog.locator('#room-key').fill(key);
  await dialog.locator('#room-name').fill(name);
  await dialog.locator('#room-guest-access').selectOption(guests);
  await dialog.getByRole('button', { name: 'Create room' }).click();
  await expect(page.locator('#room-title')).toHaveText(`# ${name}`);
}

test('a guest looks around, writes only where allowed, and a moderator ends the visit', async ({ browser }) => {
  const rootContext = await browser.newContext({ baseURL });
  const guestContext = await browser.newContext({ baseURL, viewport: { width: 1280, height: 860 } });
  const rootPage = await rootContext.newPage();
  const lounge = `Guest ${attemptName('Lounge')}`;
  const news = `Guest ${attemptName('News')}`;

  try {
    await login(rootPage, root);
    await setGuestAccess(rootPage, true);
    await rootPage.goto('/');
    await expect(rootPage.locator('#chat-shell')).toBeVisible();
    await createRoom(rootPage, attemptName('guest-lounge-e2e').toLowerCase(), lounge, 'write');
    await createRoom(rootPage, attemptName('guest-news-e2e').toLowerCase(), news, 'read');

    // A visitor goes in without an account.
    const guestPage = await guestContext.newPage();
    await guestPage.goto('/');
    await guestPage.getByRole('button', { name: /Look around as a guest/ }).click();
    await expect(guestPage.locator('#chat-shell.guest-mode')).toBeVisible();
    await expect(guestPage.locator('#guest-banner')).toContainText(/You are visiting as Guest \d{4,}/);
    const guestName = (await guestPage.locator('#current-user').innerText()).trim();
    await expect(guestPage.locator('.guest-dm-card')).toBeVisible();
    await expect(guestPage.locator('#dm-list')).toBeHidden();
    await expect(guestPage.locator('.header-search')).toBeHidden();
    // Only rooms that let guests in are listed.
    await expect(guestPage.locator('.room-button', { hasText: lounge })).toBeVisible();
    await expect(guestPage.locator('.room-button', { hasText: news })).toContainText('read only');

    // Where guests may write, the guest writes; everyone sees who it was.
    await openRoom(guestPage, lounge);
    await expect(guestPage.locator('.attachment-button')).toBeHidden();
    const text = attemptText('Hello from a passing guest');
    await guestPage.locator('#composer-input').fill(text);
    await guestPage.locator('#composer-input').press('Enter');
    await expect(guestPage.locator('.message', { hasText: text })).toBeVisible();
    await openRoom(rootPage, lounge);
    const fromGuest = rootPage.locator('.message', { hasText: text });
    await expect(fromGuest.locator('.guest-badge')).toHaveText('Guest');

    // Where guests may only read, the message box gives way to an invitation.
    await openRoom(guestPage, news);
    await expect(guestPage.locator('#guest-readonly-notice')).toBeVisible();
    await expect(guestPage.locator('#composer-form')).toBeHidden();

    // A moderator ends the visit from the guest's card; the guest is out at once.
    await fromGuest.locator('.user-name', { hasText: guestName }).click();
    const card = rootPage.locator('#name-menu');
    await expect(card).toContainText('Visiting as a guest since');
    await expect(card.getByRole('link', { name: 'Send direct message' })).toHaveCount(0);
    await card.getByRole('button', { name: 'End guest session' }).click();
    const confirm = rootPage.getByRole('dialog', { name: /End .*visit\?/ });
    await confirm.getByRole('button', { name: 'End visit' }).click();
    await expect(guestPage.locator('#auth-shell')).toBeVisible({ timeout: 15_000 });
    await expect(guestPage.locator('.toast', { hasText: 'Your guest visit has ended.' })).toBeVisible();

    // The message stays, under the guest's name.
    await rootPage.reload();
    await openRoom(rootPage, lounge);
    await expect(rootPage.locator('.message', { hasText: text })).toContainText(guestName);
  } finally {
    await setGuestAccess(rootPage, false);
    await rootContext.close();
    await guestContext.close();
  }
});
