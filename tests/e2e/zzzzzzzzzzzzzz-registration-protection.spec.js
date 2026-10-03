import { expect, test } from '@playwright/test';
import { attemptName } from './support/attempt.js';

const baseURL = process.env.CHITCHAT_BASE_URL ?? 'http://127.0.0.1:8080';
const root = {
  username: 'RootE2E',
  password: 'Correct Horse Battery Staple 2026!',
};

async function csrfToken(request) {
  const session = await (await request.get('/api/v1/session.php')).json();
  return session.csrf_token;
}

async function register(request, payload) {
  return request.post('/api/v1/register.php', {
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': await csrfToken(request) },
    data: {
      username: attemptName('ProtectedE2E'),
      password: 'Registration Protection Password 2026!',
      ...payload,
    },
  });
}

async function expectRejected(response, code) {
  expect(response.status()).toBe(400);
  expect((await response.json()).error.code).toBe(code);
}

test('registration requires a fresh challenge and rejects filled decoy fields and wrong solutions', async ({ request }) => {
  await expectRejected(await register(request, {}), 'registration_challenge_missing');

  const challenge = (await (await request.get('/api/v1/registration-challenge.php')).json()).challenge;
  expect(challenge.nonce).toMatch(/^[0-9a-f]{32}$/);
  expect(challenge.bits).toBeGreaterThan(0);
  await expectRejected(
    await register(request, { website: 'https://spam.example', challenge_nonce: challenge.nonce, challenge_solution: '0' }),
    'registration_rejected',
  );

  // The rejected submission consumed that challenge.
  await expectRejected(
    await register(request, { challenge_nonce: challenge.nonce, challenge_solution: '0' }),
    'registration_challenge_missing',
  );

  const next = (await (await request.get('/api/v1/registration-challenge.php')).json()).challenge;
  await expectRejected(
    await register(request, { challenge_nonce: next.nonce, challenge_solution: 'not-a-solution' }),
    'registration_challenge_failed',
  );
});

test('Super-Administrator tunes registration protection from operational settings', async ({ browser }) => {
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();
  const fields = ['#rp-max-attempts', '#rp-window', '#rp-min-fill', '#rp-pow-bits'];
  try {
    await page.goto('/');
    await page.locator('#login-username').fill(root.username);
    await page.locator('#login-password').fill(root.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page.locator('#chat-shell')).toBeVisible();

    await page.goto('/admin-settings.php');
    await expect(page.getByRole('heading', { name: 'Registration protection' })).toBeVisible();
    await expect(page.locator('#rp-pow-bits')).toHaveValue('');
    await expect(page.locator('#rp-pow-bits')).toHaveAttribute('placeholder', /^\d+$/);
    await expect(page.locator('#rp-effective')).toContainText('In effect:');

    await page.locator('#rp-max-attempts').fill('42');
    await page.locator('#rp-pow-bits').fill('10');
    await page.getByRole('button', { name: 'Save registration protection' }).click();
    const stepUpDialog = page.locator('.step-up-dialog');
    await expect(stepUpDialog).toBeVisible();
    await stepUpDialog.locator('#step-up-password').fill(root.password);
    await stepUpDialog.getByRole('button', { name: 'Verify password' }).click();
    await expect(page.locator('#toast-region')).toContainText('Registration protection saved.');
    await expect(page.locator('#rp-effective')).toContainText('42 attempts per IP');
    await expect(page.locator('#rp-effective')).toContainText('10-bit proof of work');

    for (const field of fields) await page.locator(field).fill('');
    await page.getByRole('button', { name: 'Save registration protection' }).click();
    await expect(page.locator('#rp-max-attempts')).toHaveValue('');
    await expect(page.locator('#rp-effective')).not.toContainText('42 attempts per IP');
  } finally {
    // Later specs register accounts, so the server defaults must apply again even after a failure.
    const session = await (await context.request.get('/api/v1/session.php')).json();
    await context.request.post('/api/v1/step-up.php', {
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': session.csrf_token },
      data: { password: root.password },
    }).catch(() => {});
    await context.request.post('/api/v1/admin/settings/registration-protection/update.php', {
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': session.csrf_token },
      data: { rate_limit_max_attempts: null, rate_limit_window_seconds: null, min_fill_seconds: null, proof_of_work_bits: null },
    }).catch(() => {});
    await context.close();
  }
});
