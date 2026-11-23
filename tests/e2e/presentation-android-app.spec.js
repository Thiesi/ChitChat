import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// #417: ChitChat for Android. With session.php's android_app set, the sign-in screen has a
// quiet "Also on Android" line under the card, and a member's You list a "ChitChat for
// Android" row with the server address to type into the app and a Copy button. Google's
// badge is the link, with the trademark line beneath. Nothing shows while android_app is
// null or missing (as on every real server until the store listing is public), and nothing
// for guests. Presentation only: the real pages against intercepted APIs, the "on" state mocked.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
test.use({ serviceWorkers: 'block' });

// The links as the server builds them (the issue's contract), here only passed through.
const STORE = 'https://play.google.com/store/apps/details?id=de.networkxxiii.chitchat&referrer=chitchat_server%3Dchat.example.org';
const OPEN = 'intent://connect?server=chat.example.org#Intent;scheme=chitchat;package=de.networkxxiii.chitchat;S.browser_fallback_url=https%3A%2F%2Fplay.google.com%2Fstore%2Fapps%2Fdetails%3Fid%3Dde.networkxxiii.chitchat%26referrer%3Dchitchat_server%253Dchat.example.org;end';
const ON = { store_url: STORE, open_url: OPEN, server_address: 'chat.example.org' };
const member = {
  id: 1, username: 'Jamie', roles: ['user'], session_version: 1, guest: false,
  rules: { accepted_version: 1, accepted_at: '2026-10-01T10:00:00Z', acceptance_required: false },
  recovery_key: { exists: true, created_at: '2026-11-01T10:00:00Z' }, username_change: null,
};
const guest = { id: 90, username: 'Guest 4821', roles: ['guest'], guest: true, session_version: 1 };

async function fixture(page, { user = null, androidApp = ON, omit = false, copyFails = false, android = false } = {}) {
  // The clipboard is the page's; here it only records what was copied (or refuses). On a
  // mocked Android the client hint says so, as Chrome on Android does.
  await page.addInitScript(({ fails, onAndroid }) => {
    window.__copied = [];
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: { writeText: async (text) => { if (fails) throw new Error('denied'); window.__copied.push(text); } },
    });
    if (onAndroid) Object.defineProperty(navigator, 'userAgentData', { configurable: true, value: { platform: 'Android', mobile: true, brands: [] } });
  }, { fails: copyFails, onAndroid: android });
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1/', '');
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    const session = {
      user, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [], guest_access: true,
      capabilities: { totp: true, signed_in_sessions: true },
    };
    if (!omit) session.android_app = androidApp;
    const responses = {
      'session.php': session,
      'users/profile.php': { profile: { id: 1, username: 'Jamie', member_since: '2025-03-14T09:00:00Z', has_avatar: false }, avatars_available: true },
      'account/mfa/status.php': { mfa: { enabled: false, available: true, credentials: [], recovery_codes_remaining: 0, usable_methods: [], totp: { enabled: false, available: true, created_at: null, last_used_at: null } } },
      'account/identities/list.php': { providers: [], identities: [], has_password: true },
      'account/sessions/list.php': { sessions: [], maximum_sessions: 50 },
      'policies.php': { policies: { rules: { source: 'none', version: 0 }, privacy: { source: 'none' } } },
      'rooms/list.php': { rooms: [] },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
}

async function axe(page, label) {
  await page.mouse.move(0, 0);
  await page.waitForTimeout(250);
  const result = await new AxeBuilder({ page }).withTags(WCAG).analyze();
  expect(result.violations, `${label}: ${result.violations.map((violation) => `${violation.id} ${violation.nodes.map((node) => node.target).join(' ')}`).join('; ')}`).toEqual([]);
}

async function signIn(page, width = 1280) {
  await page.setViewportSize({ width, height: 900 });
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
}

async function you(page, width = 1280) {
  await page.setViewportSize({ width, height: 900 });
  await page.goto('/account.php');
  await expect(page.locator('#account-loading')).toBeHidden();
}

const signInSlot = (page) => page.locator('[data-android-app="sign-in"]');
const accountSlot = (page) => page.locator('[data-android-app="account"]');
const noSideScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);

test('sign-in: "Also on Android", the badge as the link, the trademark line on its own', async ({ page }) => {
  await fixture(page);
  await signIn(page);
  const slot = signInSlot(page);
  await expect(slot).toBeVisible();
  await expect(slot.locator('.android-app-lead')).toHaveText('Also on Android');
  const badge = slot.getByRole('link', { name: 'Get it on Google Play', exact: true });
  await expect(badge).toHaveAttribute('href', STORE);
  // A plain link: same tab, nothing added.
  expect(await badge.evaluate((link) => [link.target, link.rel])).toEqual(['', '']);
  const image = badge.locator('img');
  await expect(image).toHaveAttribute('src', '/assets/badges/google-play-en.png');
  expect(await image.evaluate((img) => img.complete && img.naturalWidth)).toBe(646);
  await expect(slot.locator('.play-trademark')).toHaveText('Google Play and the Google Play logo are trademarks of Google LLC.');
  // Under the card and apart from the footer: its own block, above "Powered by".
  const order = await page.evaluate(() => {
    const card = document.querySelector('.auth-layout').getBoundingClientRect();
    const line = document.querySelector('.auth-android-app').getBoundingClientRect();
    const footer = document.querySelector('#auth-shell > .powered-by').getBoundingClientRect();
    return line.top >= card.bottom && footer.top >= line.bottom;
  });
  expect(order).toBe(true);
  // The badge is a target well over 44px; the same line serves the Register tab.
  const box = await badge.boundingBox();
  expect(box.height).toBeGreaterThanOrEqual(44);
  await page.locator('#register-tab').click();
  await expect(slot).toBeVisible();
  await axe(page, 'sign-in, light');
  await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce' });
  await axe(page, 'sign-in, dark');
});

test('sign-in on a phone: nothing sticks out, down to 320', async ({ page }) => {
  await fixture(page);
  for (const width of [390, 320]) {
    await signIn(page, width);
    await expect(signInSlot(page)).toBeVisible();
    expect(await noSideScroll(page), `${width}`).toBe(true);
  }
  await axe(page, 'sign-in, 320');
});

test('nothing shows while android_app is null or missing', async ({ page }) => {
  await fixture(page, { androidApp: null });
  await signIn(page);
  await expect(signInSlot(page)).toBeHidden();
  await expect(signInSlot(page)).toBeEmpty();

  await page.unrouteAll({ behavior: 'ignoreErrors' });
  await fixture(page, { user: member, omit: true });
  await you(page);
  await expect(accountSlot(page)).toBeHidden();
  await expect(accountSlot(page)).toBeEmpty();
});

test('You: the badge, the server address and Copy, which copies exactly that address and says so once', async ({ page }) => {
  await fixture(page, { user: member });
  await you(page);
  const slot = accountSlot(page);
  await expect(slot).toBeVisible();
  await expect(slot.getByRole('heading', { name: 'ChitChat for Android', exact: true })).toBeVisible();
  await expect(slot.getByRole('link', { name: 'Get it on Google Play', exact: true })).toHaveAttribute('href', STORE);
  await expect(slot.locator('.android-app-address')).toHaveText('Server address: chat.example.org');
  await expect(slot.locator('.play-trademark')).toBeVisible();

  const copy = slot.getByRole('button', { name: 'Copy server address', exact: true });
  const box = await copy.boundingBox();
  expect(box.height).toBeGreaterThanOrEqual(47.5);
  expect(box.width).toBeGreaterThanOrEqual(47.5);
  const status = slot.getByRole('status');
  await expect(status).toBeEmpty();
  await copy.click();
  await expect(status).toHaveText('Copied.');
  // Focus stays on the button; the copied text is the shown text, nothing more.
  await expect(copy).toBeFocused();
  expect(await page.evaluate(() => window.__copied)).toEqual(['chat.example.org']);
  await axe(page, 'You, light');
  await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce' });
  await axe(page, 'You, dark');
});

test('You: a non-default port is part of the address; without one configured, the badge alone', async ({ page }) => {
  await fixture(page, { user: member, androidApp: { store_url: STORE, server_address: 'chat.example.org:8443' } });
  await you(page);
  await expect(accountSlot(page).locator('.android-app-address')).toHaveText('Server address: chat.example.org:8443');
  await accountSlot(page).getByRole('button', { name: 'Copy server address' }).click();
  expect(await page.evaluate(() => window.__copied)).toEqual(['chat.example.org:8443']);

  await page.unrouteAll({ behavior: 'ignoreErrors' });
  await fixture(page, { user: member, androidApp: { store_url: STORE, server_address: null } });
  await you(page);
  const slot = accountSlot(page);
  await expect(slot.getByRole('link', { name: 'Get it on Google Play' })).toBeVisible();
  await expect(slot.locator('.android-app-address')).toHaveCount(0);
  await expect(slot.getByRole('button')).toHaveCount(0);
  await axe(page, 'You, badge alone');
});

test('You: when the clipboard refuses, the status says how to copy by hand', async ({ page }) => {
  await fixture(page, { user: member, copyFails: true });
  await you(page);
  await accountSlot(page).getByRole('button', { name: 'Copy server address' }).click();
  await expect(accountSlot(page).getByRole('status')).toHaveText('Couldn’t copy. Select the address to copy it.');
});

test('You on a phone and in forced colours: the row fits, down to 320', async ({ page }) => {
  await fixture(page, { user: member });
  for (const width of [390, 320]) {
    await you(page, width);
    await expect(accountSlot(page)).toBeVisible();
    expect(await noSideScroll(page), `${width}`).toBe(true);
  }
  await axe(page, 'You, 320');
  await page.emulateMedia({ forcedColors: 'active' });
  await expect(accountSlot(page).getByRole('link', { name: 'Get it on Google Play' })).toBeVisible();
  await axe(page, 'You, forced colours');
});

test('"Open it with this server" is for Android only, and only with an open link', async ({ page }) => {
  // Desktop (every test browser here): the badge, no open line, on either page.
  await fixture(page, { user: member });
  await you(page);
  await expect(accountSlot(page).getByRole('link', { name: 'Get it on Google Play' })).toBeVisible();
  await expect(accountSlot(page).locator('.android-app-open')).toHaveCount(0);

  // A mocked Android: the line under the badge, its link the app's intent, on both pages.
  await page.unrouteAll({ behavior: 'ignoreErrors' });
  await fixture(page, { user: member, android: true });
  await you(page, 390);
  const line = accountSlot(page).locator('.android-app-open');
  await expect(line).toHaveText('Already have the app? Open it with this server');
  await expect(line.getByRole('link', { name: 'Open it with this server', exact: true })).toHaveAttribute('href', OPEN);
  // The badge still leads to the listing, and the address and Copy are unchanged.
  await expect(accountSlot(page).getByRole('link', { name: 'Get it on Google Play' })).toHaveAttribute('href', STORE);
  await expect(accountSlot(page).locator('.android-app-address')).toHaveText('Server address: chat.example.org');
  const target = await line.getByRole('link').boundingBox();
  expect(target.height).toBeGreaterThanOrEqual(44);
  await axe(page, 'You, Android');

  await page.unrouteAll({ behavior: 'ignoreErrors' });
  await fixture(page, { android: true });
  await signIn(page, 390);
  await expect(signInSlot(page).getByRole('link', { name: 'Open it with this server', exact: true })).toHaveAttribute('href', OPEN);
  await axe(page, 'sign-in, Android');

  // Android without an open link (no server address configured): no line.
  await page.unrouteAll({ behavior: 'ignoreErrors' });
  await fixture(page, { android: true, androidApp: { store_url: 'https://play.google.com/store/apps/details?id=de.networkxxiii.chitchat', open_url: null, server_address: null } });
  await signIn(page, 390);
  await expect(signInSlot(page).getByRole('link', { name: 'Get it on Google Play' })).toBeVisible();
  await expect(signInSlot(page).locator('.android-app-open')).toHaveCount(0);
});

test('guests: no row in You', async ({ page }) => {
  await fixture(page, { user: guest });
  await you(page);
  await expect(accountSlot(page)).toBeHidden();
  await expect(accountSlot(page)).toBeEmpty();
});
