import AxeBuilder from '@axe-core/playwright';
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

const wcagTags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

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

async function expectNoAxeViolations(page, label) {
  const results = await new AxeBuilder({ page })
    .withTags(wcagTags)
    .analyze();
  const summary = results.violations.map((violation) => ({
    id: violation.id,
    impact: violation.impact,
    help: violation.help,
    nodes: violation.nodes.map((node) => ({
      target: node.target,
      failureSummary: node.failureSummary,
    })),
  }));

  expect(summary, `${label} accessibility violations:\n${JSON.stringify(summary, null, 2)}`).toEqual([]);
}

async function expectNoHorizontalDocumentOverflow(page, label) {
  const measurements = await page.evaluate(() => ({
    clientWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));
  expect(
    measurements.scrollWidth,
    `${label} overflows horizontally: ${JSON.stringify(measurements)}`,
  ).toBeLessThanOrEqual(measurements.clientWidth + 1);
}

function secondsFromCssList(value) {
  return value.split(',').reduce((maximum, part) => {
    const trimmed = part.trim();
    const seconds = trimmed.endsWith('ms')
      ? Number.parseFloat(trimmed) / 1_000
      : Number.parseFloat(trimmed);
    return Number.isFinite(seconds) ? Math.max(maximum, seconds) : maximum;
  }, 0);
}

test.describe.serial('ChitChat deeper accessibility validation', () => {
  test('axe-core finds no WCAG A or AA violations on core surfaces', async ({ page, browserName, browser }) => {
    test.skip(browserName !== 'chromium', 'The semantic accessibility gate runs once in Chromium.');

    await page.goto('/');
    await expect(page.locator('#auth-shell')).toBeVisible();
    await expectNoAxeViolations(page, 'Signed-out authentication');

    await page.goto('/restore-account.php');
    await expect(page.locator('main')).toBeVisible();
    await expectNoAxeViolations(page, 'Account restoration');

    await loginOrRegister(page);
    await expectNoAxeViolations(page, 'Signed-in chat');

    const pages = [
      ['/messages.php', '#messages-shell', 'Direct messages'],
      ['/search.php', '#message-search-shell', 'Message search'],
      ['/account.php', '#account-shell', 'Account'],
      ['/notifications.php', '#privacy-notifications-shell', 'Privacy notifications'],
    ];
    for (const [path, shell, label] of pages) {
      await page.goto(path);
      await expect(page.locator(shell)).toBeVisible();
      await expectNoAxeViolations(page, label);
    }

    const rootContext = await browser.newContext({ baseURL });
    try {
      const rootPage = await rootContext.newPage();
      await loginExisting(rootPage, root);
      await rootPage.goto('/moderation.php');
      await expect(rootPage.locator('#moderation-shell')).toBeVisible();
      await expectNoAxeViolations(rootPage, 'Moderation queue');
    } finally {
      await rootContext.close();
    }
  });

  test('core surfaces reflow without document-level horizontal scrolling', async ({ page, browserName, browser }) => {
    test.skip(browserName !== 'chromium', 'The deterministic reflow gate runs once in Chromium.');

    await page.setViewportSize({ width: 640, height: 900 });
    await loginOrRegister(page);
    await expectNoHorizontalDocumentOverflow(page, 'Chat at a 200%-zoom-equivalent width');

    const signedInPages = [
      ['/messages.php', '#messages-shell', 'Direct messages at 200%-zoom-equivalent width'],
      ['/search.php', '#message-search-shell', 'Message search at 200%-zoom-equivalent width'],
      ['/account.php', '#account-shell', 'Account at 200%-zoom-equivalent width'],
      ['/notifications.php', '#privacy-notifications-shell', 'Privacy notifications at 200%-zoom-equivalent width'],
    ];
    for (const [path, shell, label] of signedInPages) {
      await page.goto(path);
      await expect(page.locator(shell)).toBeVisible();
      await expectNoHorizontalDocumentOverflow(page, label);
    }

    await page.setViewportSize({ width: 320, height: 900 });
    await page.goto('/search.php');
    await expect(page.locator('#message-search-shell')).toBeVisible();
    await expectNoHorizontalDocumentOverflow(page, 'Message search at 320 CSS pixels');
    await page.goto('/account.php');
    await expect(page.locator('#account-shell')).toBeVisible();
    await expectNoHorizontalDocumentOverflow(page, 'Account at 320 CSS pixels');
    await page.goto('/notifications.php');
    await expect(page.locator('#privacy-notifications-shell')).toBeVisible();
    await expectNoHorizontalDocumentOverflow(page, 'Privacy notifications at 320 CSS pixels');

    const rootContext = await browser.newContext({ baseURL, viewport: { width: 640, height: 900 } });
    try {
      const rootPage = await rootContext.newPage();
      await loginExisting(rootPage, root);
      await rootPage.goto('/moderation.php');
      await expect(rootPage.locator('#moderation-shell')).toBeVisible();
      await expectNoHorizontalDocumentOverflow(rootPage, 'Moderation queue at 200%-zoom-equivalent width');
      await rootPage.setViewportSize({ width: 320, height: 900 });
      await expectNoHorizontalDocumentOverflow(rootPage, 'Moderation queue at 320 CSS pixels');
    } finally {
      await rootContext.close();
    }
  });

  test('forced-colors mode retains selected, primary-action, and focus affordances', async ({ page, browserName }) => {
    test.skip(browserName !== 'chromium', 'Forced-colors emulation is validated in Chromium.');

    await page.emulateMedia({ forcedColors: 'active' });
    await page.goto('/');
    await expect(page.locator('#auth-shell')).toBeVisible();

    const selectedTab = page.getByRole('tab', { name: 'Sign in' });
    await selectedTab.focus();
    const selectedStyles = await selectedTab.evaluate((element) => {
      const style = getComputedStyle(element);
      return {
        borderStyle: style.borderStyle,
        borderWidth: style.borderWidth,
        outlineStyle: style.outlineStyle,
        outlineWidth: style.outlineWidth,
      };
    });
    expect(selectedStyles.borderStyle).toBe('solid');
    expect(selectedStyles.borderWidth).not.toBe('0px');
    expect(selectedStyles.outlineStyle).not.toBe('none');
    expect(selectedStyles.outlineWidth).not.toBe('0px');

    await loginOrRegister(page);
    await page.goto('/account.php');
    await expect(page.locator('#account-shell')).toBeVisible();
    const primaryStyles = await page.locator('#personal-data-export').evaluate((element) => {
      const style = getComputedStyle(element);
      return { borderStyle: style.borderStyle, borderWidth: style.borderWidth };
    });
    expect(primaryStyles.borderStyle).toBe('solid');
    expect(primaryStyles.borderWidth).not.toBe('0px');
  });

  test('reduced-motion mode removes meaningful animation and transition durations', async ({ page, browserName }) => {
    test.skip(browserName !== 'chromium', 'Reduced-motion emulation is validated in Chromium.');

    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/');
    await expect(page.locator('#auth-shell')).toBeVisible();
    await loginOrRegister(page);
    await page.goto('/account.php');
    await expect(page.locator('#account-shell')).toBeVisible();

    const durations = await page.locator('body').evaluate((body) => {
      let animationDuration = '0s';
      let transitionDuration = '0s';
      for (const element of [body, ...body.querySelectorAll('*')]) {
        const style = getComputedStyle(element);
        if (style.animationDuration !== '0s') animationDuration += `,${style.animationDuration}`;
        if (style.transitionDuration !== '0s') transitionDuration += `,${style.transitionDuration}`;
      }
      return { animationDuration, transitionDuration };
    });

    expect(secondsFromCssList(durations.animationDuration)).toBeLessThanOrEqual(0.001);
    expect(secondsFromCssList(durations.transitionDuration)).toBeLessThanOrEqual(0.001);
    await expect(page.locator('html')).toHaveCSS('scroll-behavior', 'auto');
  });
});
