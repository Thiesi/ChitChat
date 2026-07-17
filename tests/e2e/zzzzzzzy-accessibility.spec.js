import { expect, test } from '@playwright/test';

const member = {
  username: 'MemberE2E',
  password: 'Another Correct Horse Battery Staple 2026!',
};

async function expectNamedElements(locator) {
  const count = await locator.count();
  for (let index = 0; index < count; index += 1) {
    await expect(locator.nth(index)).toHaveAccessibleName(/\S/);
  }
}

async function expectAccessibleStructure(page) {
  await expect(page.locator('html')).toHaveAttribute('lang', /^[a-z]{2}(?:-|$)/i);
  await expect(page).toHaveTitle(/\S/);

  const duplicateIds = await page.locator('[id]').evaluateAll((elements) => {
    const counts = new Map();
    for (const element of elements) {
      counts.set(element.id, (counts.get(element.id) ?? 0) + 1);
    }
    return [...counts.entries()]
      .filter(([, count]) => count > 1)
      .map(([id]) => id);
  });
  expect(duplicateIds).toEqual([]);

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

    await page.evaluate(() => document.activeElement?.blur());
    await page.keyboard.press('Tab');
    await expect(loginTab).toBeFocused();
    const focusOutline = await loginTab.evaluate((element) => {
      const style = window.getComputedStyle(element);
      return {
        style: style.outlineStyle,
        width: Number.parseFloat(style.outlineWidth),
      };
    });
    expect(focusOutline.style).not.toBe('none');
    expect(focusOutline.width).toBeGreaterThanOrEqual(2);

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
  });

  test('keeps core signed-in pages structurally accessible', async ({ page }) => {
    await loginOrRegister(page);
    await expect(page.locator('#connection-status')).toHaveAttribute('role', 'status');
    await expect(page.locator('#connection-status')).toHaveAttribute('aria-live', 'polite');
    await expectAccessibleStructure(page);

    await page.goto('/messages.php');
    await expect(page.locator('#messages-shell')).toBeVisible();
    await expectAccessibleStructure(page);

    await page.goto('/account.php');
    await expect(page.locator('#account-shell')).toBeVisible();
    await expectAccessibleStructure(page);
  });
});
