import { expect, test } from '@playwright/test';

const account = {
  username: 'PasskeyE2E',
  password: 'Passkey Browser Test Password 2026!',
};

async function register(page) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.locator('#register-tab').click();
  await page.locator('#register-username').fill(account.username);
  await page.locator('#register-password').fill(account.password);
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
  await expect(page.locator('#current-user')).toHaveText(account.username);
}

async function signOut(page) {
  if (new URL(page.url()).pathname !== '/') {
    await page.goto('/');
  }
  await expect(page.locator('#chat-shell')).toBeVisible();
  const logoutPromise = page.waitForResponse((response) => (
    response.url().endsWith('/api/v1/logout.php')
    && response.request().method() === 'POST'
  ));
  await page.locator('#logout-button').click();
  const logoutResponse = await logoutPromise;
  expect(logoutResponse.ok()).toBeTruthy();

  const sessionBootstrap = page.waitForResponse((response) => (
    response.url().endsWith('/api/v1/session.php')
    && response.request().method() === 'GET'
    && response.ok()
  ));
  await page.goto('/');
  await sessionBootstrap;
  await expect(page.locator('#auth-shell')).toBeVisible();
}

async function submitPassword(page) {
  await page.locator('#login-username').fill(account.username);
  await page.locator('#login-password').fill(account.password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('#mfa-login-panel')).toBeVisible();
}

async function completeMfaLogin(page, action) {
  const sessionPromise = page.waitForResponse((response) => (
    response.url().endsWith('/api/v1/session.php')
    && response.request().method() === 'GET'
    && response.ok()
  ));
  await action();
  await sessionPromise;
  await expect(page.locator('#chat-shell')).toBeVisible();
  await expect(page.locator('#current-user')).toHaveText(account.username);
}

test.describe('Passkey multi-factor authentication', () => {
  test('enrolls a passkey and supports passkey and one-time recovery login', async ({ browser, browserName }) => {
    test.skip(browserName !== 'chromium', 'Chromium CDP provides the virtual WebAuthn authenticator used by this journey.');

    const context = await browser.newContext();
    const page = await context.newPage();
    const client = await context.newCDPSession(page);
    await client.send('WebAuthn.enable');
    const { authenticatorId } = await client.send('WebAuthn.addVirtualAuthenticator', {
      options: {
        protocol: 'ctap2',
        transport: 'internal',
        hasResidentKey: true,
        hasUserVerification: true,
        isUserVerified: true,
        automaticPresenceSimulation: true,
      },
    });

    try {
      await register(page);
      await page.goto('/account.php');
      await expect(page.locator('#account-shell')).toBeVisible();
      await expect(page.locator('#mfa-add-form')).toBeVisible();
      await page.locator('#mfa-label').fill('Chromium virtual authenticator');
      await page.locator('#mfa-add').click();

      const passwordDialog = page.locator('dialog[open]', { hasText: 'Confirm this sensitive action' });
      await expect(passwordDialog).toBeVisible();
      await passwordDialog.locator('#step-up-password').fill(account.password);
      await passwordDialog.getByRole('button', { name: 'Verify password' }).click();

      const recoveryDialog = page.locator('dialog[open]', { hasText: 'Save your recovery codes now' });
      await expect(recoveryDialog).toBeVisible();
      const recoveryText = await recoveryDialog.locator('.recovery-code-list').textContent();
      const recoveryCode = recoveryText?.trim().split(/\s+/u)[0] ?? '';
      expect(recoveryCode).toMatch(/^[A-F0-9]{4}(?:-[A-F0-9]{4}){5}$/u);
      await recoveryDialog.getByRole('button', { name: 'I saved them' }).click();

      await expect(page.locator('#mfa-summary')).toContainText('Multi-factor authentication is enabled');
      await expect(page.locator('.account-credential')).toContainText('Chromium virtual authenticator');
      await expect(page.locator('#mfa-recovery-status')).toContainText('10 unused recovery codes remain');

      await signOut(page);
      await submitPassword(page);
      await completeMfaLogin(page, () => page.locator('#mfa-login-passkey').click());

      await signOut(page);
      await submitPassword(page);
      await page.locator('#mfa-login-recovery-code').fill(recoveryCode);
      await completeMfaLogin(page, () => page.getByRole('button', { name: 'Use recovery code' }).click());

      await signOut(page);
      await submitPassword(page);
      await page.locator('#mfa-login-recovery-code').fill(recoveryCode);
      await page.getByRole('button', { name: 'Use recovery code' }).click();
      await expect(page.locator('#mfa-login-panel [role="alert"]')).toContainText('invalid or has already been used');
      await expect(page.locator('#auth-shell')).toBeVisible();
    } finally {
      await client.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId }).catch(() => {});
      await client.send('WebAuthn.disable').catch(() => {});
      await context.close();
    }
  });
});
