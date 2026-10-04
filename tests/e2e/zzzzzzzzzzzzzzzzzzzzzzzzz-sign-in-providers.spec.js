import { expect, test } from '@playwright/test';
import { attemptName, attemptSuffix } from './support/attempt.js';
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

// Confirms a sensitive action through the Google window instead of a password.
async function confirmWithGoogle(page) {
  const dialog = page.getByRole('dialog', { name: 'Confirm this sensitive action' });
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('#step-up-password')).toBeHidden();
  const popup = page.waitForEvent('popup');
  await dialog.getByRole('button', { name: 'Confirm with Google' }).click();
  await popup;
  await expect(dialog).toBeHidden({ timeout: 20_000 });
}

test('someone new signs up with Google, confirms and restores through it, then adds a password', async ({ browser }) => {
  test.skip(!process.env.GOOGLE_OIDC_CLIENT_ID, 'The mock sign-in provider is configured in CI only.');
  provider.useSubject(`google-newcomer${attemptSuffix()}`);
  const username = attemptName('GoogleNewcomer');
  const password = 'Newcomer Picked A Password Later 2026!';
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();

  // Signing up: Google confirms who this is, the person only picks a username.
  await page.goto('/');
  await page.getByRole('link', { name: 'Continue with Google' }).click();
  await expect(page.locator('#register-provider-note')).toContainText('Signing up with Google');
  await expect(page.locator('#register-password')).toBeHidden();
  await page.locator('#register-username').fill(username);
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page.locator('#chat-shell')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#current-user')).toHaveText(username);

  // Without a password, closing the account is confirmed with Google.
  await page.goto('/account.php');
  await expect(page.locator('#password-submit')).toHaveText('Set a password');
  await page.getByLabel(/I understand that I will be signed out immediately/).check();
  await page.getByRole('button', { name: 'Request account closure' }).click();
  await confirmWithGoogle(page);
  await expect(page.locator('#account-closure-status')).toContainText('Closure requested.');
  await page.waitForURL((url) => url.pathname === '/');

  // ...and restored with Google, since there is no password to restore with.
  await page.goto('/restore-account.php');
  await page.getByRole('button', { name: 'Restore with Google' }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
  await expect(page.locator('#current-user')).toHaveText(username);

  // Setting a password is confirmed with Google too; afterwards it signs in.
  await page.goto('/account.php');
  await page.locator('#password-new').fill(password);
  await page.getByRole('button', { name: 'Set a password' }).click();
  await confirmWithGoogle(page);
  await expect(page.locator('#password-status')).toContainText('Your password is set');
  await expect(page.locator('#password-submit')).toHaveText('Change password');
  await context.close();

  const fresh = await browser.newContext({ baseURL });
  const signIn = await fresh.newPage();
  await signIn.goto('/');
  await signIn.locator('#login-username').fill(username);
  await signIn.locator('#login-password').fill(password);
  await signIn.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(signIn.locator('#chat-shell')).toBeVisible();
  await fresh.close();
});
