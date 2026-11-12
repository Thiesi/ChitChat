import { expect, test } from '@playwright/test';
import { completeRecoveryKeyStep } from './support/recovery.js';

const visualAccount = {
  username: 'VisualE2E',
  password: 'Visual Regression Password 2026!',
};

async function loginOrRegister(page) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.locator('#login-username').fill(visualAccount.username);
  await page.locator('#login-password').fill(visualAccount.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();

  try {
    await page.locator('#chat-shell').waitFor({ state: 'visible', timeout: 5_000 });
    return;
  } catch {
    await expect(page.locator('#auth-error')).not.toHaveText('');
  }

  await page.getByRole('tab', { name: 'Register' }).click();
  await page.locator('#register-username').fill(visualAccount.username);
  await page.locator('#register-password').fill(visualAccount.password);
  await page.getByRole('button', { name: 'Create account' }).click();
  await completeRecoveryKeyStep(page);
}

// Soft assertions, so a run reports (and captures) every changed layout at once.
const screenshotOptions = {
  animations: 'disabled',
  caret: 'hide',
  fullPage: true,
  maxDiffPixelRatio: 0.003,
  threshold: 0.2,
};

test('critical authentication and account layouts remain visually stable', async ({ page, browserName }) => {
  test.skip(browserName !== 'chromium', 'Visual baselines are intentionally limited to pinned Chromium on Linux.');

  await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce' });
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await expect.soft(page).toHaveScreenshot('auth-desktop.png', screenshotOptions);

  await loginOrRegister(page);
  // The Account page previews the date format with the current time, so pin the clock.
  await page.clock.setFixedTime(new Date('2026-10-04T12:00:00Z'));
  // The recovery key's creation date is the real registration time; pin it too,
  // so the line (and its wrapping at narrow widths) is the same on every day.
  await page.route('**/api/v1/session.php', async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    if (body?.user?.recovery_key?.created_at) body.user.recovery_key.created_at = '2026-10-04T12:00:00Z';
    await route.fulfill({ response, json: body });
  });
  // "Where you're signed in" (#205) lists real sign-in times, and a retry signs
  // in again (another row): show only this browser, at the pinned time.
  await page.route('**/api/v1/account/sessions/list.php', async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    const current = (body.sessions ?? []).filter((entry) => entry.current)
      .map((entry) => ({ ...entry, created_at: '2026-10-04T11:58:00Z', last_active_at: '2026-10-04T12:00:00Z' }));
    await route.fulfill({ response, json: { ...body, sessions: current } });
  });
  // "Member since" (S5a) is the real registration day: pin it like the dates above.
  await page.route('**/api/v1/users/profile.php?**', async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    if (body?.profile?.member_since) body.profile.member_since = '2026-10-04T12:00:00Z';
    await route.fulfill({ response, json: body });
  });
  // You shows one section at a time since S5a (#171). The desktop baseline is
  // Sign-in and security, the section with the most kinds of control (fields,
  // the recovery row, passkeys, sign-ins); the narrow one is the phone's first
  // screen, the list of sections.
  await page.goto('/account.php#security');
  await expect(page.locator('#account-shell')).toBeVisible();
  await expect(page.locator('#account-loading')).toBeHidden();
  await expect(page.locator('#you-member-since')).toHaveText(/Member since/);
  // The passkey, sign-in and recovery cards fill in from separate requests.
  await page.waitForLoadState('networkidle');
  await expect.soft(page).toHaveScreenshot('account-desktop.png', screenshotOptions);

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/account.php');
  await expect(page.locator('#you-list')).toBeVisible();
  await expect(page.locator('#account-loading')).toBeHidden();
  await expect(page.locator('#you-member-since')).toHaveText(/Member since/);
  await page.waitForLoadState('networkidle');
  await expect.soft(page).toHaveScreenshot('account-narrow.png', screenshotOptions);
});
