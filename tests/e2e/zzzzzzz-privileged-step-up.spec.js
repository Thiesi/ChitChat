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

async function login(page, account) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.locator('#login-username').fill(account.username);
  await page.locator('#login-password').fill(account.password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
}

async function session(context) {
  const response = await context.request.get('/api/v1/session.php');
  expect(response.status()).toBe(200);
  return response.json();
}

async function post(context, csrf, path, payload) {
  return context.request.post(path, {
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrf,
    },
    data: payload,
  });
}

async function expectError(response, status, code) {
  expect(response.status()).toBe(status);
  const payload = await response.json();
  expect(payload.error.code).toBe(code);
}

test('sensitive endpoint families require recent password verification after role authorization', async ({ browser }) => {
  const adminContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });

  try {
    const adminPage = await adminContext.newPage();
    await login(adminPage, admin);
    const adminSession = await session(adminContext);
    expect(adminSession.security.privileged_step_up.active).toBe(false);
    const adminCsrf = adminSession.csrf_token;
    const adminId = adminSession.user.id;

    const protectedRequests = [
      ['/api/v1/admin/direct-messages/inspect.php', {
        user_a_id: 1,
        user_b_id: 2,
        reason: 'Testing the direct-message step-up boundary',
        before_id: null,
        limit: 20,
      }],
      ['/api/v1/admin/message-revisions/review.php', {
        kind: 'room',
        message_id: 1,
        reason: 'Testing the message-revision step-up boundary',
      }],
      ['/api/v1/admin/roles.php', {
        target_user_id: 2,
        roles: [],
      }],
      ['/api/v1/admin/reset-password.php', {
        target_user_id: 2,
        new_password: 'A replacement password that must not be applied',
      }],
      ['/api/v1/admin/settings/update.php', {
        registration_enabled: true,
        room_message_retention_days: 0,
        direct_message_retention_days: 0,
        audit_retention_days: 0,
        deleted_attachment_retention_days: 30,
        orphan_attachment_grace_hours: 24,
        realtime_event_retention_hours: 168,
        login_attempt_retention_days: 30,
      }],
    ];

    for (const [path, payload] of protectedRequests) {
      await expectError(await post(adminContext, adminCsrf, path, payload), 403, 'step_up_required');
    }

    const memberPage = await memberContext.newPage();
    await login(memberPage, member);
    const memberSession = await session(memberContext);
    await expectError(
      await post(memberContext, memberSession.csrf_token, '/api/v1/admin/roles.php', {
        target_user_id: adminId,
        roles: [],
      }),
      403,
      'forbidden',
    );

    await expectError(
      await post(adminContext, adminCsrf, '/api/v1/step-up.php', { password: 'wrong current password' }),
      403,
      'step_up_invalid_credentials',
    );
    const verified = await post(adminContext, adminCsrf, '/api/v1/step-up.php', { password: admin.password });
    expect(verified.status()).toBe(200);
    const verifiedPayload = await verified.json();
    expect(verifiedPayload.privileged_step_up.active).toBe(true);
    expect(verifiedPayload.privileged_step_up.method).toBe('password');

    await expectError(
      await post(adminContext, adminCsrf, '/api/v1/admin/roles.php', {
        target_user_id: adminId,
        roles: ['super_admin'],
      }),
      400,
      'self_role_change_forbidden',
    );
  } finally {
    await memberContext.close();
    await adminContext.close();
  }
});
