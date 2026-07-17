import { expect, test } from '@playwright/test';

const baseURL = process.env.CHITCHAT_BASE_URL ?? 'http://127.0.0.1:8080';
const admin = {
  username: 'RootE2E',
  password: 'Correct Horse Battery Staple 2026!',
};

async function login(page) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.locator('#login-username').fill(admin.username);
  await page.locator('#login-password').fill(admin.password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
}

test('Administrator sees shared operational status and metrics remain disabled by default', async ({ browser }) => {
  const context = await browser.newContext({ baseURL });
  try {
    const page = await context.newPage();
    await login(page);
    await page.goto('/admin.php');
    await expect(page.locator('#admin-shell')).toBeVisible();
    await expect(page.locator('#system-status-link')).toBeVisible();
    await page.locator('#system-status-link').click();

    await expect(page.locator('#status-shell')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'System status' })).toBeVisible();
    await expect(page.locator('#database-size')).not.toHaveText('—');
    await expect(page.locator('#database-latency')).toContainText('ms');
    await expect(page.locator('#attachment-active')).toHaveText(/^[0-9]+$/);
    await expect(page.locator('#sse-connections')).toHaveText(/^[0-9]+$/);
    await expect(page.locator('#maintenance-state')).toHaveText(/^(Current|Overdue)$/);
    await expect(page.locator('#metrics-enabled')).toHaveText('Disabled');

    const policyTable = page.getByRole('table', { name: 'Effective rate-limit policies' });
    await expect(policyTable).toBeVisible();
    const roomSendRow = policyTable.getByRole('row', { name: /room_send/ });
    await expect(roomSendRow).toContainText('30 / 1m');
    await expect(roomSendRow.getByRole('cell')).toHaveCount(5);
    await expect(policyTable.getByRole('row', { name: /privileged_step_up/ })).toContainText('10 / 15m');

    const apiResponse = await context.request.get('/api/v1/admin/system-status.php');
    expect(apiResponse.status()).toBe(200);
    const payload = await apiResponse.json();
    expect(payload.status.application.name).toBe('ChitChat');
    expect(typeof payload.status.maintenance.overdue).toBe('boolean');
    expect(payload.status.security.rate_limit_policies.room_send).toEqual({
      name: 'room_send',
      maximum_attempts: 30,
      window_seconds: 60,
    });
    expect(Array.isArray(payload.status.security.rate_limit_decisions)).toBe(true);

    const metricsResponse = await context.request.get('/metrics.php');
    expect(metricsResponse.status()).toBe(404);
  } finally {
    await context.close();
  }
});
