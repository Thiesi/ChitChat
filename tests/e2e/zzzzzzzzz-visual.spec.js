import { expect, test } from '@playwright/test';

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
  await expect(page.locator('#chat-shell')).toBeVisible();
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
  await page.goto('/account.php');
  await expect(page.locator('#account-shell')).toBeVisible();
  await expect(page.locator('#account-loading')).toBeHidden();
  await expect(page.locator('#date-preview-medium')).not.toBeEmpty();
  await expect.soft(page).toHaveScreenshot('account-desktop.png', screenshotOptions);

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/account.php');
  await expect(page.locator('#account-shell')).toBeVisible();
  await expect(page.locator('#account-loading')).toBeHidden();
  await expect(page.locator('#date-preview-medium')).not.toBeEmpty();
  await expect.soft(page).toHaveScreenshot('account-narrow.png', screenshotOptions);
});
