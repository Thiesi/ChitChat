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

async function register(page, account) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.locator('#register-tab').click();
  await expect(page.locator('#register-form')).toBeVisible();
  await page.locator('#register-username').fill(account.username);
  await page.locator('#register-password').fill(account.password);
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
  await expect(page.locator('#current-user')).toHaveText(account.username);
  await expect(page.locator('#connection-status')).toHaveText('Live', { timeout: 20_000 });
}

async function selectDirectMessagePeer(page, username) {
  await page.locator('#dm-user-search').fill(username);
  await page.getByRole('button', { name: 'Search' }).click();
  await page.locator('.dm-user-button', { hasText: username }).click();
  await expect(page.locator('#dm-peer-name')).toHaveText(username);
  await expect(page.locator('#dm-block-toggle')).toBeVisible();
}

async function setRegistrationPolicy(page, enabled, {
  password = null,
  expectPrompt = false,
  rejectWrongPassword = false,
} = {}) {
  await page.locator('#registration-enabled').selectOption(enabled ? '1' : '0');
  const isSuccessfulSettingsResponse = (response) => (
    response.url().endsWith('/api/v1/admin/settings/update.php')
    && response.request().method() === 'POST'
    && response.ok()
  );
  const stepUpDialog = page.locator('.step-up-dialog');

  page.once('dialog', async (dialog) => dialog.accept());
  if (!expectPrompt) {
    const responsePromise = page.waitForResponse(isSuccessfulSettingsResponse);
    await page.locator('#save-settings').click();
    await expect(stepUpDialog).toBeHidden();
    const response = await responsePromise;
    expect(response.ok()).toBeTruthy();
  } else {
    await page.locator('#save-settings').click();
    await expect(stepUpDialog).toBeVisible();
    if (rejectWrongPassword) {
      await stepUpDialog.locator('#step-up-password').fill('Definitely not the current password');
      await stepUpDialog.getByRole('button', { name: 'Verify password' }).click();
      await expect(stepUpDialog.locator('.step-up-error')).toContainText('current password is incorrect');
      await expect(stepUpDialog).toBeVisible();
    }

    await stepUpDialog.locator('#step-up-password').fill(password);
    const responsePromise = page.waitForResponse(isSuccessfulSettingsResponse);
    await stepUpDialog.getByRole('button', { name: 'Verify password' }).click();
    await expect(stepUpDialog).toBeHidden();
    const response = await responsePromise;
    expect(response.ok()).toBeTruthy();
  }

  await expect(page.locator('#registration-enabled')).toHaveValue(enabled ? '1' : '0');
}

test.describe.serial('ChitChat browser release checks', () => {
  test('emits hardened HTTP headers and protects anonymous APIs', async ({ request }) => {
    const pageResponse = await request.get('/');
    expect(pageResponse.status()).toBe(200);
    const headers = pageResponse.headers();
    expect(headers['content-security-policy']).toContain("default-src 'self'");
    expect(headers['content-security-policy']).toContain("frame-ancestors 'none'");
    expect(headers['cache-control']).toContain('no-store');
    expect(headers['x-content-type-options']).toBe('nosniff');
    expect(headers['x-frame-options']).toBe('DENY');
    expect(headers['referrer-policy']).toBe('no-referrer');
    expect(headers['permissions-policy']).toContain('microphone=()');

    const protectedResponse = await request.get('/api/v1/direct-messages/conversations.php');
    expect(protectedResponse.status()).toBe(401);
  });

  test('supports rooms, realtime chat, attachments, DMs and operational settings', async ({ browser }) => {
    const adminContext = await browser.newContext({ baseURL });
    const memberContext = await browser.newContext({ baseURL });
    let anonymousContext = null;

    try {
      const adminPage = await adminContext.newPage();
      await register(adminPage, admin);
      await expect(adminPage.locator('#admin-link')).toBeVisible();

      await adminPage.locator('#new-room-button').click();
      const roomDialog = adminPage.locator('#room-dialog');
      await expect(roomDialog).toBeVisible();
      await roomDialog.locator('#room-key').fill('general-e2e');
      await roomDialog.locator('#room-name').fill('General E2E');
      await roomDialog.locator('#room-info-line').fill('Browser release validation');
      await roomDialog.getByRole('button', { name: 'Create room' }).click();
      await expect(adminPage.locator('#room-title')).toHaveText('# General E2E');

      const memberPage = await memberContext.newPage();
      await register(memberPage, member);
      const publicRoom = memberPage.locator('.room-button', { hasText: '# General E2E' });
      await expect(publicRoom).toBeVisible();
      await publicRoom.click();
      await expect(memberPage.locator('#room-title')).toHaveText('# General E2E');
      await expect(memberPage.locator('#join-button')).toBeVisible();
      await memberPage.locator('#join-button').click();
      await expect(memberPage.locator('#composer-wrap')).toBeVisible();
      await expect(memberPage.locator('#admin-link')).toBeHidden();

      await expect(adminPage.locator('#presence-list')).toContainText(member.username, { timeout: 20_000 });
      await expect(memberPage.locator('#presence-list')).toContainText(admin.username, { timeout: 20_000 });

      await memberPage.locator('#composer-input').fill('Hello from the member browser');
      await memberPage.locator('#send-button').click();
      await expect(adminPage.locator('.message-body', { hasText: 'Hello from the member browser' })).toBeVisible();

      await adminPage.locator('#composer-input').fill('/me confirms realtime delivery');
      await adminPage.locator('#send-button').click();
      await expect(memberPage.locator('.message.emote .message-body', { hasText: 'confirms realtime delivery' })).toBeVisible();

      await adminPage.locator('#composer-input').fill(`/ping ${member.username} Browser ping`);
      await adminPage.locator('#send-button').click();
      await expect(memberPage.locator('#toast-region')).toContainText('Browser ping');

      await memberPage.locator('#attachment-input').setInputFiles({
        name: 'browser-e2e.txt',
        mimeType: 'text/plain',
        buffer: Buffer.from('attachment delivered through the browser\n'),
      });
      await memberPage.locator('#composer-input').fill('Release-test attachment');
      await memberPage.locator('#send-button').click();
      await expect(memberPage.locator('#toast-region')).toContainText('Attachment uploaded');
      const adminDownload = adminPage.locator('a.attachment-download', { hasText: 'browser-e2e.txt' });
      await expect(adminDownload).toBeVisible({ timeout: 20_000 });
      const href = await adminDownload.getAttribute('href');
      expect(href).not.toBeNull();
      const downloadResponse = await adminContext.request.get(href);
      expect(downloadResponse.status()).toBe(200);
      expect(await downloadResponse.text()).toBe('attachment delivered through the browser\n');
      expect(downloadResponse.headers()['content-disposition']).toContain('attachment');

      const adminMessages = await adminContext.newPage();
      await adminMessages.goto('/messages.php');
      await expect(adminMessages.locator('#messages-shell')).toBeVisible();
      await expect(adminMessages.locator('#dm-privacy-text')).toContainText('not end-to-end encrypted');
      await selectDirectMessagePeer(adminMessages, member.username);
      await expect(adminMessages.locator('#dm-composer')).toBeVisible();

      const memberMessages = await memberContext.newPage();
      await memberMessages.goto('/messages.php');
      await expect(memberMessages.locator('#messages-shell')).toBeVisible();
      await selectDirectMessagePeer(memberMessages, admin.username);
      await expect(memberMessages.locator('#dm-composer')).toBeVisible();
      await memberMessages.locator('#dm-message-input').fill('Private browser hello');
      await memberMessages.locator('#dm-send').click();
      await expect(adminMessages.locator('.dm-message-body', { hasText: 'Private browser hello' })).toBeVisible();

      await adminMessages.locator('#dm-message-input').fill('Private browser reply');
      await adminMessages.locator('#dm-send').click();
      await expect(memberMessages.locator('.dm-message-body', { hasText: 'Private browser reply' })).toBeVisible();

      await memberMessages.locator('#dm-block-toggle').click();
      await expect(memberMessages.locator('#dm-block-toggle')).toHaveText('Unblock user');
      await expect(memberMessages.locator('#dm-peer-status')).toContainText('You blocked this user');
      await expect(memberMessages.locator('#dm-composer')).toBeHidden();
      await expect(memberMessages.locator('.dm-message-body', { hasText: 'Private browser reply' })).toBeVisible();

      await adminMessages.locator('#dm-message-input').fill('This message must be blocked');
      await adminMessages.locator('#dm-send').click();
      await expect(adminMessages.locator('#messages-error')).toContainText('Direct messaging is unavailable');
      await expect(adminMessages.locator('#dm-peer-status')).toContainText('Direct messaging is unavailable');
      await expect(adminMessages.locator('#dm-composer')).toBeHidden();
      await expect(memberMessages.locator('.dm-message-body', { hasText: 'This message must be blocked' })).toHaveCount(0);

      await memberMessages.locator('#dm-block-toggle').click();
      await expect(memberMessages.locator('#dm-block-toggle')).toHaveText('Block user');
      await expect(memberMessages.locator('#dm-composer')).toBeVisible();

      await selectDirectMessagePeer(adminMessages, member.username);
      await expect(adminMessages.locator('#dm-composer')).toBeVisible();
      await adminMessages.locator('#dm-message-input').fill('Messaging resumed after unblock');
      await adminMessages.locator('#dm-send').click();
      await expect(memberMessages.locator('.dm-message-body', { hasText: 'Messaging resumed after unblock' })).toBeVisible();

      const adminConsole = await adminContext.newPage();
      await adminConsole.goto('/admin.php');
      await expect(adminConsole.getByRole('heading', { name: 'Administration', exact: true })).toBeVisible();
      await expect(adminConsole.locator('#system-settings-link')).toBeVisible();
      await expect(adminConsole.locator('#dm-inspection-link')).toBeVisible();
      await adminConsole.close();

      await Promise.all([
        adminPage.close(),
        memberPage.close(),
        adminMessages.close(),
        memberMessages.close(),
      ]);

      const settingsPage = await adminContext.newPage();
      await settingsPage.goto('/admin-settings.php');
      await expect(settingsPage.locator('#settings-shell')).toBeVisible();
      await expect(settingsPage.locator('#registration-enabled')).toHaveValue('1');
      await expect(settingsPage.locator('#room-retention')).toHaveValue('0');
      await expect(settingsPage.locator('#dm-retention')).toHaveValue('0');

      await setRegistrationPolicy(settingsPage, false, {
        password: admin.password,
        expectPrompt: true,
        rejectWrongPassword: true,
      });

      const sessionAfterStepUp = await adminContext.request.get('/api/v1/session.php');
      const sessionPayload = await sessionAfterStepUp.json();
      expect(sessionPayload.security.privileged_step_up.active).toBe(true);
      expect(sessionPayload.security.privileged_step_up.method).toBe('password');

      anonymousContext = await browser.newContext({ baseURL });
      const anonymousPage = await anonymousContext.newPage();
      await anonymousPage.goto('/');
      await expect(anonymousPage.locator('#auth-shell')).toBeVisible();
      await expect(anonymousPage.locator('#register-tab')).toBeHidden();

      await setRegistrationPolicy(settingsPage, true, {
        expectPrompt: false,
      });
    } finally {
      await anonymousContext?.close();
      await memberContext.close();
      await adminContext.close();
    }
  });
});
