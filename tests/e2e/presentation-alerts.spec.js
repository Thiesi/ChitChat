import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// #150 on the web: "New direct messages" is an opt-in alert, off by default,
// and the account-wide alert choices are shown whether or not this browser
// is subscribed, or can subscribe at all. Presentation only: the real page
// and modules against intercepted API responses.
const viewer = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };

async function fixture(page, { pushConfigured = true, devices = [], preferences = {} } = {}) {
  const saved = [];
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace('/api/v1/', '');
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'push/update-preferences.php') {
      saved.push(request.postDataJSON());
      return route.fulfill({ json: { preferences: {} } });
    }
    const responses = {
      'session.php': {
        user: viewer, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [],
        web_push: pushConfigured ? { enabled: true, vapid_public_key: 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U' } : { enabled: false },
      },
      // An older-shaped response: no direct_message_push_enabled, so the switch starts off.
      'push/preferences.php': { preferences: { mentioned_push_enabled: true, pinged_push_enabled: true, quiet_hours: null, ...preferences }, devices },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return saved;
}

async function withoutBrowserPush(page) {
  await page.addInitScript(() => { delete window.PushManager; });
}

test('choices are shown and saved without a browser subscription; the DM switch is off by default', async ({ page }) => {
  await withoutBrowserPush(page);
  const saved = await fixture(page);
  await page.goto('/account.php');

  await expect(page.getByRole('heading', { name: 'Alerts', level: 2 })).toBeVisible();
  await expect(page.locator('#push-unsupported')).toBeVisible();
  await expect(page.locator('#push-subscribe-toggle')).toBeHidden();
  await expect(page.locator('#push-no-device')).toBeVisible();

  const dm = page.getByRole('switch', { name: 'New direct messages' });
  await expect(dm).not.toBeChecked();
  await expect(dm).toHaveAccessibleDescription('Someone writes to you directly. The alert never shows what they wrote.');
  await expect(page.getByRole('switch', { name: 'Mentions' })).toBeChecked();
  await expect(page.getByRole('switch', { name: 'Pings' })).toBeChecked();
  await expect(page.locator('.push-overlap')).toHaveText('If one message is both a direct message and a mention, you get one alert, not two.');

  await dm.check();
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('#push-preferences-status')).toHaveText('Saved. You’ll be alerted about new direct messages from now on.');
  expect(saved.at(-1)).toMatchObject({ direct_message_push_enabled: true, mentioned_push_enabled: true, pinged_push_enabled: true });

  // Saving again with no new opt-in just says "Saved."
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('#push-preferences-status')).toHaveText('Saved.');
});

test('a saved opt-in loads as on; devices replace the no-device note', async ({ page }) => {
  await withoutBrowserPush(page);
  await fixture(page, {
    preferences: { direct_message_push_enabled: true },
    devices: [
      { id: 4, user_agent: 'Firefox on Windows', created_at: '2026-11-02T10:00:00Z' },
      { id: 5, user_agent: '', created_at: '2026-11-07T10:00:00Z' },
    ],
  });
  await page.goto('/account.php');
  await expect(page.getByRole('switch', { name: 'New direct messages' })).toBeChecked();
  await expect(page.locator('#push-no-device')).toBeHidden();
  await expect(page.locator('#push-device-list li')).toHaveCount(2);
  await expect(page.locator('#push-device-list li').nth(1)).toContainText('Other device');
  await expect(page.locator('#push-device-list li').nth(0)).toContainText('since');
});

test('a browser that can show alerts offers to turn them on here', async ({ page, browserName }) => {
  // Only Chromium gives Playwright a working service worker and push manager
  // (Firefox's never becomes ready, WebKit's push support is partial). The
  // tests above cover the engine-neutral promise, choices shown without push
  // support, in every engine.
  test.skip(browserName !== 'chromium', 'Push subscription needs Chromium in the test harness.');
  await fixture(page);
  await page.goto('/account.php');
  await expect(page.locator('#push-settings')).toBeVisible();
  await expect(page.locator('#push-browser')).toBeVisible();
  await expect(page.locator('#push-browser-status')).toHaveText('Alerts are off in this browser.');
  await expect(page.getByRole('button', { name: 'Turn on alerts here' })).toBeEnabled();
});

test('a server without alerts says so and shows no choices', async ({ page }) => {
  await fixture(page, { pushConfigured: false });
  await page.goto('/account.php');
  await expect(page.locator('#push-status')).toHaveText('Alerts aren’t set up on this server.');
  await expect(page.locator('#push-settings')).toBeHidden();
  await expect(page.locator('#push-browser')).toBeHidden();
});

for (const theme of ['light', 'dark']) {
  test(`${theme}: the alerts card passes axe and reflows at 320px`, async ({ page }) => {
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await withoutBrowserPush(page);
    await fixture(page);
    await page.setViewportSize({ width: 320, height: 800 });
    await page.goto('/account.php');
    await expect(page.locator('#push-settings')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    const result = await new AxeBuilder({ page }).include('section[aria-labelledby="push-heading"]').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations).toEqual([]);
  });
}

// #212: how long alerts stay on screen is the device's choice, explained in a
// closed disclosure under "This browser", with no links or external assets.
test('the "How long alerts stay on screen" note is closed, opens from the keyboard, and names the system settings', async ({ page }) => {
  await withoutBrowserPush(page);
  await fixture(page);
  await page.goto('/account.php');
  const help = page.locator('#push-help');
  const summary = help.locator('summary');
  await expect(summary).toHaveText('How long alerts stay on screen');
  await expect(help).not.toHaveAttribute('open', /.*/);
  await expect(help.getByText(/Dismiss notifications after this amount of time/)).toBeHidden();

  await summary.focus();
  await expect(summary).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(help).toHaveAttribute('open', '');
  await expect(help.getByText('Your device shows alerts, so it decides where they appear and how long they stay.', { exact: false })).toBeVisible();
  await expect(help.locator('li')).toHaveCount(3);
  await expect(help.locator('li').nth(0)).toContainText('Settings ›, Accessibility ›, Visual effects ›, “Dismiss notifications after this amount of time”');
  await expect(help.locator('li').nth(1)).toContainText('“Persistent”');
  await expect(help.locator('li').nth(2)).toContainText('notification shade');
  // The › separators are hidden from screen readers, which hear commas instead.
  await expect(help.locator('li').nth(0).locator('[aria-hidden="true"]')).toHaveCount(3);
  expect(await help.locator('a, img').count()).toBe(0);
  await page.keyboard.press('Space');
  await expect(help).not.toHaveAttribute('open', /.*/);
});

for (const theme of ['light', 'dark']) {
  test(`${theme}: the open alerts help passes axe`, async ({ page }) => {
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await withoutBrowserPush(page);
    await fixture(page);
    await page.goto('/account.php');
    await page.locator('#push-help summary').click();
    await expect(page.locator('#push-help')).toHaveAttribute('open', '');
    const result = await new AxeBuilder({ page }).include('#push-browser').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations).toEqual([]);
  });
}
