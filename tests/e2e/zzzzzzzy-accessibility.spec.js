import { expect, test } from '@playwright/test';

const member = {
  username: 'MemberE2E',
  password: 'Another Correct Horse Battery Staple 2026!',
};

async function expectAccessibleStructure(page) {
  await expect(page.locator('html')).toHaveAttribute('lang', /^[a-z]{2}(?:-|$)/i);
  await expect(page).toHaveTitle(/\S/);

  const issues = await page.evaluate(() => {
    const problems = [];
    const isVisible = (element) => {
      if (!(element instanceof HTMLElement) || element.hidden) return false;
      const style = window.getComputedStyle(element);
      if (style.display === 'none' || style.visibility === 'hidden') return false;
      const rect = element.getBoundingClientRect();
      return rect.width > 0 || rect.height > 0;
    };
    const referencedText = (element) => {
      const ids = (element.getAttribute('aria-labelledby') ?? '').trim().split(/\s+/).filter(Boolean);
      return ids.map((id) => document.getElementById(id)?.textContent ?? '').join(' ').trim();
    };
    const accessibleName = (element) => (
      element.getAttribute('aria-label')
      ?? referencedText(element)
      ?? element.getAttribute('title')
      ?? element.textContent
      ?? ''
    ).trim();

    const idCounts = new Map();
    for (const element of document.querySelectorAll('[id]')) {
      idCounts.set(element.id, (idCounts.get(element.id) ?? 0) + 1);
    }
    for (const [id, count] of idCounts) {
      if (count > 1) problems.push(`duplicate id: ${id}`);
    }

    const visibleMains = [...document.querySelectorAll('main')].filter(isVisible);
    if (visibleMains.length !== 1) problems.push(`visible main landmarks: ${visibleMains.length}`);

    const visibleH1s = [...document.querySelectorAll('h1')].filter(isVisible);
    if (visibleH1s.length !== 1) problems.push(`visible h1 headings: ${visibleH1s.length}`);

    for (const image of document.querySelectorAll('img')) {
      if (isVisible(image) && !image.hasAttribute('alt')) problems.push('visible image without alt text');
    }

    for (const control of document.querySelectorAll('input, select, textarea')) {
      if (!isVisible(control) || control.getAttribute('type') === 'hidden') continue;
      const hasLabel = 'labels' in control && control.labels !== null && control.labels.length > 0;
      const hasName = accessibleName(control) !== '';
      if (!hasLabel && !hasName) problems.push(`unlabelled control: ${control.tagName.toLowerCase()}#${control.id}`);
    }

    for (const interactive of document.querySelectorAll('button, a[href], summary, [role="button"], [role="tab"]')) {
      if (isVisible(interactive) && accessibleName(interactive) === '') {
        problems.push(`unnamed interactive element: ${interactive.tagName.toLowerCase()}#${interactive.id}`);
      }
    }

    for (const dialog of document.querySelectorAll('dialog[open], [role="dialog"]')) {
      if (isVisible(dialog) && accessibleName(dialog) === '') problems.push(`unnamed dialog: #${dialog.id}`);
    }

    return problems;
  });

  expect(issues).toEqual([]);
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
