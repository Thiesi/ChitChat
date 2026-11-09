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
  await page.goto('/notifications.php');

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
  await page.goto('/notifications.php');
  await expect(page.getByRole('switch', { name: 'New direct messages' })).toBeChecked();
  await expect(page.locator('#push-no-device')).toBeHidden();
  await expect(page.locator('#push-device-list li')).toHaveCount(2);
  await expect(page.locator('#push-device-list li').nth(1)).toContainText('Other device');
  await expect(page.locator('#push-device-list li').nth(0)).toContainText('since');
});

test('a browser that can show alerts offers to turn them on here', async ({ page }) => {
  await fixture(page);
  await page.goto('/notifications.php');
  await expect(page.locator('#push-browser-status')).toHaveText('Alerts are off in this browser.');
  await expect(page.getByRole('button', { name: 'Turn on alerts here' })).toBeEnabled();
  await expect(page.locator('#push-settings')).toBeVisible();
});

test('a server without alerts says so and shows no choices', async ({ page }) => {
  await fixture(page, { pushConfigured: false });
  await page.goto('/notifications.php');
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
    await page.goto('/notifications.php');
    await expect(page.locator('#push-settings')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    const result = await new AxeBuilder({ page }).include('section[aria-labelledby="push-heading"]').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations).toEqual([]);
  });
}
