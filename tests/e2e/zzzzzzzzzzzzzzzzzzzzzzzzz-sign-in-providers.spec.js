import { expect, test } from '@playwright/test';
import { startMockOidc } from './support/mock-oidc.js';

const baseURL = process.env.CHITCHAT_BASE_URL ?? 'http://127.0.0.1:8080';
const member = {
  username: 'MemberE2E',
  password: 'Another Correct Horse Battery Staple 2026!',
};

let provider;
test.beforeAll(async () => { provider = await startMockOidc(); });
test.afterAll(async () => { await provider?.close(); });

async function passStepUp(page) {
  const dialog = page.locator('.step-up-dialog');
  try {
    await dialog.waitFor({ state: 'visible', timeout: 3_000 });
  } catch {
    return;
  }
  await dialog.locator('#step-up-password').fill(member.password);
  await dialog.getByRole('button', { name: 'Verify password' }).click();
  await expect(dialog).toBeHidden();
}

test('a member connects Google and then signs in with it instead of a password', async ({ browser }) => {
  test.skip(!process.env.GOOGLE_OIDC_CLIENT_ID, 'The mock sign-in provider is configured in CI only.');
  provider.useSubject('member-e2e-google');

  const memberContext = await browser.newContext({ baseURL });
  const page = await memberContext.newPage();
  await page.goto('/');
  await page.locator('#login-username').fill(member.username);
  await page.locator('#login-password').fill(member.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();

  await page.goto('/account.php');
  const methods = page.locator('#sign-in-methods');
  await expect(methods).toBeVisible();
  const google = methods.locator('.sign-in-method', { hasText: 'Google' });
  if (await google.getByRole('button', { name: 'Connect Google' }).isVisible()) {
    await google.getByRole('button', { name: 'Connect Google' }).click();
    await passStepUp(page);
  }
  await expect(page).toHaveURL(/\/account\.php$/);
  await expect(google).toContainText('Connected');

  try {
    // A fresh browser signs in through the provider, no password involved.
    const visitorContext = await browser.newContext({ baseURL });
    const visitor = await visitorContext.newPage();
    await visitor.goto('/');
    await visitor.getByRole('link', { name: 'Continue with Google' }).click();
    await expect(visitor.locator('#chat-shell')).toBeVisible();
    await expect(visitor.locator('#current-user')).toHaveText(member.username);
    await visitorContext.close();

    // A provider account nobody connected leads back with an explanation.
    provider.useSubject('stranger-e2e-google');
    const strangerContext = await browser.newContext({ baseURL });
    const stranger = await strangerContext.newPage();
    await stranger.goto('/');
    await stranger.getByRole('link', { name: 'Continue with Google' }).click();
    await expect(stranger.locator('#auth-error')).toContainText('No account here is connected to this Google account');
    await expect(stranger.locator('#chat-shell')).toBeHidden();
    await strangerContext.close();
  } finally {
    await page.goto('/account.php');
    await google.getByRole('button', { name: 'Disconnect Google' }).click();
    await passStepUp(page);
    await expect(google).toContainText('Not connected');
    await memberContext.close();
  }
});
