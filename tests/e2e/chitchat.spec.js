import { expect, test } from '@playwright/test';
import {
  attemptName,
  attemptText,
  registerOrSignIn,
} from './support/attempt.js';

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
  await page.getByRole('button', { name: 'Search', exact: true }).click();
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

async function apiPost(context, path, payload) {
  const session = await (await context.request.get('/api/v1/session.php')).json();
  return context.request.post(path, {
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': session.csrf_token },
    data: payload,
  });
}

// Later specs register accounts, so a failure must never leave registration disabled.
async function restoreRegistration(context, password) {
  await apiPost(context, '/api/v1/step-up.php', { password });
  const { settings } = await (await context.request.get('/api/v1/admin/settings/get.php')).json();
  await apiPost(context, '/api/v1/admin/settings/update.php', { ...settings, registration_enabled: true });
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
    let memberBlockedAdminId = null;
    let registrationDisabled = false;
    const helloText = attemptText('Hello from the member browser');
    const emoteText = attemptText('confirms realtime delivery');
    const attachmentName = `${attemptName('browser-e2e')}.txt`;
    const privateHello = attemptText('Private browser hello');
    const privateReply = attemptText('Private browser reply');
    const blockedText = attemptText('This message must be blocked');
    const resumedText = attemptText('Messaging resumed after unblock');

    try {
      const adminPage = await adminContext.newPage();
      await registerOrSignIn(adminPage, admin, register);
      await adminPage.getByRole('button', { name: 'Account menu' }).click();
      await expect(adminPage.locator('#admin-link')).toBeVisible();
      await adminPage.keyboard.press('Escape');

      // Every later spec uses this room, so a retry reuses it rather than recreating it.
      const { rooms } = await (await adminContext.request.get('/api/v1/rooms/list.php')).json();
      if (rooms.some((room) => room.key === 'general-e2e')) {
        await adminPage.locator('.room-button', { hasText: '# General E2E' }).click();
      } else {
        await adminPage.locator('#new-room-button').click();
        const roomDialog = adminPage.locator('#room-dialog');
        await expect(roomDialog).toBeVisible();
        await roomDialog.locator('#room-key').fill('general-e2e');
        await roomDialog.locator('#room-name').fill('General E2E');
        await roomDialog.locator('#room-info-line').fill('Browser release validation');
        await roomDialog.getByRole('button', { name: 'Create room' }).click();
      }
      await expect(adminPage.locator('#room-title')).toHaveText('# General E2E');

      const memberPage = await memberContext.newPage();
      await registerOrSignIn(memberPage, member, register);
      const publicRoom = memberPage.locator('.room-button', { hasText: '# General E2E' });
      await expect(publicRoom).toBeVisible();
      await publicRoom.click();
      await expect(memberPage.locator('#room-title')).toHaveText('# General E2E');
      // Both elements are always present; whichever is visible says whether a retry already joined.
      const joinButton = memberPage.locator('#join-button:visible');
      const composer = memberPage.locator('#composer-wrap:visible');
      await expect(joinButton.or(composer)).toBeVisible();
      if (await joinButton.count() > 0) {
        await joinButton.click();
      }
      await expect(composer).toBeVisible();
      await expect(memberPage.locator('#admin-link')).toBeHidden();

      await expect(adminPage.locator('#presence-list')).toContainText(member.username, { timeout: 20_000 });
      await expect(memberPage.locator('#presence-list')).toContainText(admin.username, { timeout: 20_000 });

      await memberPage.locator('#composer-input').fill(helloText);
      await memberPage.locator('#composer-input').press('Enter');
      await expect(adminPage.locator('.message-body', { hasText: helloText })).toBeVisible();

      // Formatting is built as elements, and links open safely in a new tab.
      const formattedText = attemptText('Formatting check');
      await memberPage.locator('#composer-input').fill(`${formattedText} *bold* \`code\` https://example.org/formatting <b>raw</b>`);
      await memberPage.locator('#composer-input').press('Enter');
      const formatted = adminPage.locator('.message-body', { hasText: formattedText });
      await expect(formatted.locator('strong')).toHaveText('bold');
      await expect(formatted.locator('code.message-code')).toHaveText('code');
      const link = formatted.getByRole('link', { name: 'https://example.org/formatting' });
      await expect(link).toHaveAttribute('href', 'https://example.org/formatting');
      await expect(link).toHaveAttribute('target', '_blank');
      await expect(link).toHaveAttribute('rel', 'noopener noreferrer nofollow');
      await expect(formatted.locator('b')).toHaveCount(0);
      await expect(formatted).toContainText('<b>raw</b>');

      await adminPage.locator('#composer-input').fill(`/me ${emoteText}`);
      await adminPage.locator('#composer-input').press('Enter');
      await expect(memberPage.locator('.message.emote .message-body', { hasText: emoteText })).toBeVisible();

      await adminPage.locator('#composer-input').fill(`/ping ${member.username} Browser ping`);
      await adminPage.locator('#composer-input').press('Enter');
      // In the open room a ping is a private notice in the timeline.
      await expect(memberPage.locator('.ping-notice', { hasText: 'Browser ping' })).toContainText(`${admin.username} pinged you`);

      await memberPage.locator('#attachment-input').setInputFiles({
        name: attachmentName,
        mimeType: 'text/plain',
        buffer: Buffer.from('attachment delivered through the browser\n'),
      });
      await memberPage.locator('#composer-input').fill('Release-test attachment');
      await memberPage.locator('#composer-input').press('Enter');
      await expect(memberPage.locator('#toast-region')).toContainText('Attachment uploaded');
      const adminDownload = adminPage.locator('a.attachment-download', { hasText: attachmentName });
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
      await memberMessages.locator('#dm-message-input').fill(privateHello);
      await memberMessages.locator('#dm-message-input').press('Enter');
      await expect(adminMessages.locator('.dm-message-body', { hasText: privateHello })).toBeVisible();

      await adminMessages.locator('#dm-message-input').fill(privateReply);
      await adminMessages.locator('#dm-message-input').press('Enter');
      await expect(memberMessages.locator('.dm-message-body', { hasText: privateReply })).toBeVisible();

      memberBlockedAdminId = (await (await adminContext.request.get('/api/v1/session.php')).json()).user.id;
      await memberMessages.locator('#dm-block-toggle').click();
      await expect(memberMessages.locator('#dm-block-toggle')).toHaveText('Unblock user');
      await expect(memberMessages.locator('#dm-peer-status')).toContainText('You blocked this user');
      await expect(memberMessages.locator('#dm-composer')).toBeHidden();
      await expect(memberMessages.locator('.dm-message-body', { hasText: privateReply })).toBeVisible();

      await adminMessages.locator('#dm-message-input').fill(blockedText);
      await adminMessages.locator('#dm-message-input').press('Enter');
      await expect(adminMessages.locator('#messages-error')).toContainText('Direct messaging is unavailable');
      await expect(adminMessages.locator('#dm-peer-status')).toContainText('Direct messaging is unavailable');
      await expect(adminMessages.locator('#dm-composer')).toBeHidden();
      await expect(memberMessages.locator('.dm-message-body', { hasText: blockedText })).toHaveCount(0);

      await memberMessages.locator('#dm-block-toggle').click();
      await expect(memberMessages.locator('#dm-block-toggle')).toHaveText('Block user');
      await expect(memberMessages.locator('#dm-composer')).toBeVisible();
      memberBlockedAdminId = null;

      await selectDirectMessagePeer(adminMessages, member.username);
      await expect(adminMessages.locator('#dm-composer')).toBeVisible();
      await adminMessages.locator('#dm-message-input').fill(resumedText);
      await adminMessages.locator('#dm-message-input').press('Enter');
      await expect(memberMessages.locator('.dm-message-body', { hasText: resumedText })).toBeVisible();

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

      registrationDisabled = true;
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
      registrationDisabled = false;
    } finally {
      if (memberBlockedAdminId !== null) {
        await apiPost(memberContext, '/api/v1/direct-messages/unblock.php', { user_id: memberBlockedAdminId })
          .catch(() => {});
      }
      if (registrationDisabled) {
        await restoreRegistration(adminContext, admin.password).catch(() => {});
      }
      await anonymousContext?.close();
      await memberContext.close();
      await adminContext.close();
    }
  });
});
