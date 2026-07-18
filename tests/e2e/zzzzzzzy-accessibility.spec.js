import { expect, test } from '@playwright/test';

const baseURL = process.env.CHITCHAT_BASE_URL ?? 'http://127.0.0.1:8080';
const member = {
  username: 'MemberE2E',
  password: 'Another Correct Horse Battery Staple 2026!',
};
const root = {
  username: 'RootE2E',
  password: 'Correct Horse Battery Staple 2026!',
};

async function expectNamedElements(locator) {
  const count = await locator.count();
  for (let index = 0; index < count; index += 1) {
    await expect(locator.nth(index)).toHaveAccessibleName(/\S/);
  }
}

async function expectUniqueIds(page) {
  const html = await page.content();
  const ids = [...html.matchAll(/\sid=(?:"([^"]+)"|'([^']+)')/gi)]
    .map((match) => match[1] ?? match[2]);
  const seen = new Set();
  const duplicates = new Set();
  for (const id of ids) {
    if (seen.has(id)) duplicates.add(id);
    seen.add(id);
  }

  expect([...duplicates]).toEqual([]);
}

async function expectAccessibleStructure(page) {
  await expect(page.locator('html')).toHaveAttribute('lang', /^[a-z]{2}(?:-|$)/i);
  await expect(page).toHaveTitle(/\S/);
  await expectUniqueIds(page);

  await expect(page.locator('main:visible')).toHaveCount(1);
  await expect(page.locator('h1:visible')).toHaveCount(1);
  await expect(page.locator('img:visible:not([alt])')).toHaveCount(0);

  await expectNamedElements(page.locator([
    'input:visible:not([type="hidden"])',
    'select:visible',
    'textarea:visible',
  ].join(', ')));
  await expectNamedElements(page.locator([
    'button:visible',
    'a[href]:visible',
    'summary:visible',
    '[role="button"]:visible',
    '[role="tab"]:visible',
  ].join(', ')));
  await expectNamedElements(page.locator('dialog[open]:visible, [role="dialog"]:visible'));
}

async function loginExisting(page, account) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.locator('#login-username').fill(account.username);
  await page.locator('#login-password').fill(account.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
}

async function loginOrRegister(page) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.locator('#login-username').fill(member.username);
  await page.locator('#login-password').fill(member.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();

  try {
    await page.locator('#chat-shell').waitFor({ state: 'visible', timeout: 5_000 });
    return;
  } catch {
    await expect(page.locator('#auth-error')).not.toHaveText('');
  }

  await page.getByRole('tab', { name: 'Register' }).click();
  await page.locator('#register-username').fill(member.username);
  await page.locator('#register-password').fill(member.password);
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
}

test.describe.serial('ChitChat accessibility checks', () => {
  test('supports keyboard-operated auth tabs with visible focus', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('#auth-shell')).toBeVisible();
    await expectAccessibleStructure(page);

    const loginTab = page.getByRole('tab', { name: 'Sign in' });
    const registerTab = page.getByRole('tab', { name: 'Register' });
    await expect(loginTab).toHaveAttribute('aria-controls', 'login-form');
    await expect(registerTab).toHaveAttribute('aria-controls', 'register-form');

    await page.keyboard.press('Tab');
    await expect(loginTab).toBeFocused();
    await expect(loginTab).toHaveCSS('outline-style', /^(?!none$).+/);
    await expect(loginTab).toHaveCSS('outline-width', '3px');

    await page.keyboard.press('ArrowRight');
    await expect(registerTab).toBeFocused();
    await expect(registerTab).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#register-form')).toBeVisible();
    await expect(page.locator('#login-form')).toBeHidden();

    await page.keyboard.press('ArrowLeft');
    await expect(loginTab).toBeFocused();
    await expect(loginTab).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#login-form')).toBeVisible();
    await expect(page.locator('#register-form')).toBeHidden();

    await expect(page.locator('#room-dialog')).toHaveAttribute('aria-labelledby', 'room-dialog-title');
    await expect(page.locator('#message-report-dialog')).toHaveAttribute('aria-labelledby', 'message-report-title');
  });

  test('keeps search, reporting, and moderation surfaces structurally accessible', async ({ page, browser }) => {
    await loginOrRegister(page);
    await expect(page.locator('#connection-status')).toHaveAttribute('role', 'status');
    await expect(page.locator('#connection-status')).toHaveAttribute('aria-live', 'polite');
    await expectAccessibleStructure(page);

    const reportButton = page.getByRole('button', { name: 'Report', exact: true }).first();
    await expect(reportButton).toBeVisible({ timeout: 15_000 });
    await reportButton.click();
    await expect(page.locator('#message-report-dialog')).toBeVisible();
    await expectAccessibleStructure(page);
    await page.locator('#message-report-cancel').click();
    await expect(page.locator('#message-report-dialog')).toBeHidden();

    await page.goto('/messages.php');
    await expect(page.locator('#messages-shell')).toBeVisible();
    await expectAccessibleStructure(page);

    await page.goto('/search.php');
    await expect(page.locator('#message-search-shell')).toBeVisible();
    await expectAccessibleStructure(page);

    await page.goto('/account.php');
    await expect(page.locator('#account-shell')).toBeVisible();
    await expect(page.locator('#mfa-summary')).not.toHaveText('');
    await expectAccessibleStructure(page);

    const rootContext = await browser.newContext({ baseURL });
    try {
      const rootPage = await rootContext.newPage();
      await loginExisting(rootPage, root);
      await rootPage.goto('/moderation.php');
      await expect(rootPage.locator('#moderation-shell')).toBeVisible();
      await expectAccessibleStructure(rootPage);
    } finally {
      await rootContext.close();
    }
  });
});
