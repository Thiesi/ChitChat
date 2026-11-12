import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// S3 (#169): onboarding and authentication in Lantern. The installation's
// lockup is the headline (#227: its own icon, its name as the h1); the art is
// drawn from Lantern shapes; the seasonal decoration (#242's
// sign_in_decoration) sits around the lockup on the sign-in screen only.
// 16px fields, pill buttons, show/hide on every password field, and the
// strips for lockdown and "signed out". A restyle: the behaviour is unchanged,
// and the parity checklist lives here and in the recovery, rules, TOTP and
// sessions specs. Presentation only: real pages against intercepted APIs.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const member = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };

async function fixture(page, { session = {}, requests = [], overrides = {} } = {}) {
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace('/api/v1/', '');
    if (request.method() === 'POST') requests.push({ path, body: request.postDataJSON?.() ?? null });
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path in overrides) {
      const value = typeof overrides[path] === 'function' ? overrides[path](request) : overrides[path];
      if (Number.isInteger(value?.status)) return route.fulfill({ status: value.status, json: value.json });
      return route.fulfill({ json: value });
    }
    const responses = {
      'session.php': {
        user: null, csrf_token: 'fixture', registration_enabled: true, guest_access: true,
        sign_in_providers: [{ id: 'google', label: 'Google' }], ...session,
      },
      'registration-challenge.php': { challenge: null },
      'password-reset/challenge.php': { challenge: null },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return requests;
}

async function open(page, path = '/') {
  await page.goto(path);
  await expect(page.locator('#auth-shell')).toBeVisible();
}

async function settled(page) {
  await page.evaluate(() => document.fonts.ready);
  await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}

async function noOverflow(page) {
  await settled(page);
  const [scroll, inner] = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
  expect(scroll).toBeLessThanOrEqual(inner);
}

async function axe(page, label) {
  await page.mouse.move(0, 0);
  const result = await new AxeBuilder({ page }).withTags(WCAG).analyze();
  expect(result.violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) })), label).toEqual([]);
}

const card = (page) => page.locator('.auth-card');

const SEASONS = ['winter', 'spring', 'celebration'];
const LONG_NAME = 'The Riverside Neighbours and Friends of the Old Mill Community Hall';

/** Boxes of the season's drawings that overlap the lockup's name. */
async function overlapsWithName(page) {
  return page.evaluate(() => {
    const name = document.querySelector('.auth-intro .lockup-name').getBoundingClientRect();
    return [...document.querySelectorAll('[data-season] svg svg > *')].filter((item) => {
      const box = item.getBoundingClientRect();
      return box.right > name.left && box.left < name.right && box.bottom > name.top && box.top < name.bottom;
    }).length;
  });
}

test('the lockup is the headline: the installation’s icon and its name, then a greeting that follows the tab', async ({ page }) => {
  await fixture(page);
  await open(page);
  const heading = page.getByRole('heading', { level: 1 });
  await expect(heading).toHaveText('ChitChat');
  await expect(heading).toHaveClass(/lockup-name/);
  // The icon is decorative: the name beside it is the text. Unchanged, as the installation set it.
  await expect(page.locator('.auth-intro .lockup-mark img')).toHaveAttribute('alt', '');
  await expect(page.locator('.auth-intro .lockup-mark img')).toHaveAttribute('src', /app-icons\/icon-192\.png|installation-icon\.php/);
  // An installation called ChitChat doesn't repeat itself (LockupTest covers renamed ones).
  await expect(page.locator('.lockup-sub')).toHaveCount(0);
  await expect(page).toHaveTitle(/ChitChat/);
  // The old tagline and welcome block are gone.
  await expect(page.locator('.tagline, .welcome-title, .guest-intro-cta')).toHaveCount(0);

  await expect(card(page).getByRole('heading', { level: 2 })).toHaveText('Welcome back');
  await page.getByRole('tab', { name: 'Register' }).click();
  await expect(card(page).getByRole('heading', { level: 2 })).toHaveText('Make yourself at home');
  await page.getByRole('tab', { name: 'Sign in' }).click();
  await expect(card(page).getByRole('heading', { level: 2 })).toHaveText('Welcome back');
  // The art is decoration, out of the accessibility tree.
  await expect(page.locator('.auth-art')).toHaveAttribute('aria-hidden', 'true');
});

test('a long installation name wraps, never truncates, and fits at 320px and at 200% zoom', async ({ page }) => {
  await fixture(page, { session: { sign_in_decoration: 'celebration' } });
  for (const width of [1280, 640, 320]) {
    await page.setViewportSize({ width, height: 800 });
    await open(page);
    await page.locator('.auth-intro .lockup-name').evaluate((node, name) => { node.textContent = name; }, LONG_NAME);
    await noOverflow(page);
    const box = await page.locator('.auth-intro .lockup-name').boundingBox();
    expect(box.x, `${width}`).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width, `${width}`).toBeLessThanOrEqual(width);
    expect(await page.locator('.auth-intro .lockup-name').evaluate((node) => getComputedStyle(node).textOverflow)).not.toBe('ellipsis');
    expect(await overlapsWithName(page), `${width}: decoration over the name`).toBe(0);
  }
});

test('16px fields and pill buttons, so phones don’t zoom and every step has one clear primary', async ({ page }) => {
  await fixture(page);
  await page.setViewportSize({ width: 360, height: 800 });
  await open(page);
  for (const selector of ['#login-username', '#login-password']) {
    await expect(page.locator(selector)).toHaveCSS('font-size', '16px');
  }
  await page.getByRole('tab', { name: 'Register' }).click();
  for (const selector of ['#register-username', '#register-password', '#register-birth-date']) {
    await expect(page.locator(selector)).toHaveCSS('font-size', '16px');
  }
  await expect(page.locator('#register-form .primary-button')).toHaveCount(1);
  const radius = await page.locator('#register-form .primary-button').evaluate((node) => parseFloat(getComputedStyle(node).borderTopLeftRadius));
  const height = await page.locator('#register-form .primary-button').evaluate((node) => node.getBoundingClientRect().height);
  expect(radius).toBeGreaterThanOrEqual(height / 2 - 1);
  await page.getByRole('tab', { name: 'Sign in' }).click();
  await expect(page.locator('#login-form .primary-button')).toHaveCount(1);
});

test('show/hide on the sign-in, registration and restore password fields', async ({ page }) => {
  await fixture(page, { overrides: { 'login.php': { status: 401, json: { error: { code: 'invalid_credentials', message: 'Wrong username or password.' } } } } });
  await open(page);
  for (const [tab, field] of [['Sign in', '#login-password'], ['Register', '#register-password']]) {
    await page.getByRole('tab', { name: tab }).click();
    const input = page.locator(field);
    const toggle = page.locator(`${field} + .password-toggle`);
    await expect(toggle).toHaveAccessibleName('Show password');
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');
    await input.fill('a long secret phrase');
    await toggle.click();
    await expect(input).toHaveAttribute('type', 'text');
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');
    await expect(toggle).toHaveAccessibleName('Hide password');
    await expect(input).toHaveValue('a long secret phrase');
    await toggle.press('Enter');
    await expect(input).toHaveAttribute('type', 'password');
  }
  // A shown password goes back to hidden when the form is sent.
  await page.getByRole('tab', { name: 'Sign in' }).click();
  await page.locator('#login-username').fill('Jamie');
  await page.locator('#login-password + .password-toggle').click();
  await page.locator('#login-form .primary-button').click();
  await expect(page.locator('#login-password')).toHaveAttribute('type', 'password');

  await page.goto('/restore-account.php');
  const restore = page.locator('#restore-password');
  await restore.fill('another secret phrase');
  await page.locator('#restore-password + .password-toggle').click();
  await expect(restore).toHaveAttribute('type', 'text');
  await expect(page.locator('#restore-password + .password-toggle')).toHaveAccessibleName('Hide password');
});

test('the step-up dialog’s password has show/hide too', async ({ page }) => {
  await fixture(page, { overrides: {
    'account/mfa/status.php': { mfa: { enabled: false, available: false, credentials: [] } },
    'account/identities/list.php': { providers: [], identities: [], has_password: true },
  } });
  await open(page);
  await page.evaluate(async () => { (await import('/assets/js/step-up.js')).verifyCurrentPassword().catch(() => {}); });
  const dialog = page.getByRole('dialog', { name: 'Confirm this sensitive action' });
  await expect(dialog).toBeVisible();
  const input = dialog.locator('#step-up-password');
  await expect(input).toBeFocused();
  await expect(input).toHaveCSS('font-size', '16px');
  await dialog.getByRole('button', { name: 'Show password' }).click();
  await expect(input).toHaveAttribute('type', 'text');
  await expect(dialog.getByRole('button', { name: 'Hide password' })).toHaveAttribute('aria-pressed', 'true');
  await axe(page, 'step-up dialog');
});

for (const season of SEASONS) {
  test(`${season}: drawn around the lockup, static and hidden from assistive tech, on the sign-in screen only`, async ({ page }) => {
    await fixture(page, { session: { sign_in_decoration: season } });
    await open(page);
    const layer = page.locator('[data-season]');
    await expect(layer).toBeVisible();
    await expect(layer).toHaveAttribute('aria-hidden', 'true');
    await expect(layer).toHaveAttribute('data-season-kind', season);
    expect(await layer.locator('svg svg').count()).toBeGreaterThanOrEqual(5);
    // Static: nothing moves.
    expect(await page.evaluate(() => document.querySelector('[data-season]').getAnimations({ subtree: true }).length)).toBe(0);
    // The mark is untouched: the decoration is a separate layer behind the lockup.
    await expect(page.locator('.lockup-mark svg')).toHaveCount(0);
    expect(await overlapsWithName(page)).toBe(0);
    // Gone in forced colours.
    await page.emulateMedia({ forcedColors: 'active' });
    await expect(layer).toBeHidden();
    await page.emulateMedia({ forcedColors: 'none' });
    // Never on Restore.
    await page.goto('/restore-account.php');
    await expect(page.locator('[data-season]')).toHaveCount(0);
  });
}

test('no decoration when none is chosen or the value is unknown', async ({ page }) => {
  for (const value of [undefined, null, 'halloween']) {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
    await fixture(page, { session: { sign_in_decoration: value } });
    await open(page);
    await expect(page.locator('[data-season]')).toBeHidden();
    await expect(page.locator('[data-season] *')).toHaveCount(0);
  }
});

test('lockdown and "signed out" each show as one strip on the card', async ({ page }) => {
  await page.addInitScript(() => {
    if (!sessionStorage.getItem('seeded')) {
      sessionStorage.setItem('seeded', '1');
      sessionStorage.setItem('chitchat-signed-out', 'You were signed out from another device. Sign in again.');
    }
  });
  await fixture(page, { session: { lockdown: { enabled: true, message: 'Back at 18:00.' } } });
  await open(page);
  await expect(page.locator('#auth-lockdown')).toHaveText('Maintenance. Back at 18:00.');
  await expect(page.locator('#auth-notice')).toHaveText('You were signed out from another device. Sign in again.');
  await expect(page.locator('#auth-notice')).toHaveAttribute('role', 'status');
  // Once: a reload has no line.
  await page.reload();
  await expect(page.locator('#auth-shell')).toBeVisible();
  await expect(page.locator('#auth-notice')).toBeHidden();
});

test('parity: guest start, the provider link, the honeypot out of reach, and restore', async ({ page }) => {
  const requests = await fixture(page, { overrides: { 'guest/start.php': { status: 500, json: { error: { code: 'server_error', message: 'No.' } } } } });
  await open(page);
  await expect(page.getByRole('link', { name: 'Continue with Google' })).toHaveAttribute('href', '/api/v1/oidc/start.php?provider=google');
  await expect(page.getByRole('button', { name: 'Look around as a guest' })).toHaveCount(1);
  await page.getByRole('button', { name: 'Look around as a guest' }).click();
  await expect.poll(() => requests.some((entry) => entry.path === 'guest/start.php')).toBe(true);
  await page.getByRole('tab', { name: 'Register' }).click();
  await expect(page.locator('#register-website')).toHaveAttribute('tabindex', '-1');
  await expect(page.locator('.registration-trap')).toHaveAttribute('aria-hidden', 'true');
  await expect(page.locator('.registration-trap')).not.toBeInViewport();
  await page.getByRole('tab', { name: 'Sign in' }).click();
  await page.getByRole('link', { name: 'Restore a closing account' }).click();
  await expect(page).toHaveURL(/restore-account\.php$/);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('ChitChat');
  await expect(page.getByRole('heading', { level: 2 })).toHaveText('Restore your account');
  await expect(page.locator('.lockup.lockup-horizontal')).toBeVisible();
});

test('MFA after a password: the authenticator code first, then passkey, then a recovery code; no toggle on the code', async ({ page }) => {
  await fixture(page, { overrides: { 'login.php': { mfa_required: true, methods: ['totp', 'passkey'] } } });
  await open(page);
  await page.locator('#login-username').fill('Jamie');
  await page.locator('#login-password').fill('a long secret phrase');
  await page.locator('#login-form .primary-button').click();
  const panel = page.locator('#mfa-login-panel');
  await expect(panel).toBeVisible();
  // The card's greeting steps aside for the panel's own heading.
  await expect(page.locator('#auth-greeting')).toBeHidden();
  const order = await panel.evaluate((root) => [...root.querySelectorAll('#mfa-login-totp-code, #mfa-login-passkey, #mfa-login-recovery-code')].map((node) => node.id));
  expect(order).toEqual(['mfa-login-totp-code', 'mfa-login-passkey', 'mfa-login-recovery-code']);
  const code = panel.locator('#mfa-login-totp-code');
  await expect(code).toHaveAttribute('inputmode', 'numeric');
  await expect(code).toHaveAttribute('autocomplete', 'one-time-code');
  await expect(code).toHaveCSS('font-size', '16px');
  await expect(panel.locator('.password-toggle')).toHaveCount(0);
  await axe(page, 'MFA panel');
});

// Every state in light, dark and one variant (Lounge), at 1280 and 360px.
for (const [theme, scheme, season] of [['light', 'lantern', null], ['dark', 'lantern', 'winter'], ['light', 'lounge', 'spring'], ['dark', 'lounge', 'celebration']]) {
  test(`${theme} ${scheme}: sign-in, register and restore pass axe at 1280 and 360px${season ? ` with ${season}` : ''}`, async ({ page }, testInfo) => {
    await page.addInitScript(([choice, colours]) => {
      localStorage.setItem('chitchat-theme', choice);
      localStorage.setItem('chitchat-scheme', colours);
    }, [theme, scheme]);
    await fixture(page, { session: { sign_in_decoration: season, lockdown: { enabled: true, message: 'Back soon.' } } });
    for (const width of [1280, 360]) {
      await page.setViewportSize({ width, height: 900 });
      await open(page);
      await settled(page);
      await axe(page, `${width} sign in`);
      await noOverflow(page);
      await page.screenshot({ path: testInfo.outputPath(`auth-${theme}-${scheme}-${width}.png`), fullPage: true });
      await page.getByRole('tab', { name: 'Register' }).click();
      await page.locator('#register-password + .password-toggle').focus();
      await axe(page, `${width} register`);
      await page.goto('/restore-account.php');
      await expect(page.locator('#restore-account-form')).toBeVisible();
      await axe(page, `${width} restore`);
      await noOverflow(page);
    }
  });
}

// ---- Review of #246 ----

const REVOKED = 'You were signed out from another device. Sign in again.';
const GENERIC = 'Your session has ended. Please sign in again.';
const rooms = [{ id: 1, name: 'The living room', key: 'room-0', visibility: 'public', member_role: 'member', minimum_age: 0, inactivity_timeout_seconds: 0, info_line: '', unread_count: 0 }];

async function signedInWithStream(page, onMessages) {
  await page.addInitScript(() => {
    class FakeEventSource extends EventTarget {
      constructor(url) {
        super();
        this.url = url;
        this.readyState = 1;
        (window.__sources ??= []).push(this);
        window.setTimeout(() => this.dispatchEvent(new Event('open')), 0);
      }

      close() { this.readyState = 2; }
    }
    window.EventSource = FakeEventSource;
    window.__emitRaw = (type, data) => {
      for (const source of window.__sources ?? []) source.dispatchEvent(new MessageEvent(type, { data: JSON.stringify(data) }));
    };
  });
  let signedIn = true;
  await fixture(page, { overrides: {
    'session.php': () => ({ user: signedIn ? member : null, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] }),
    'rooms/list.php': { rooms },
    'logout.php': () => { signedIn = false; return {}; },
  } });
  await page.route('**/api/v1/rooms/messages.php?**', onMessages);
  return { signOut: () => { signedIn = false; } };
}

const generic401 = { status: 401, json: { error: { code: 'authentication_required', message: 'Sign in.' } } };

test('#205: a plain 401 arriving after "signed out from another device" never replaces that line', async ({ page }) => {
  let release;
  const held = new Promise((resolve) => { release = resolve; });
  const session = await signedInWithStream(page, async (route) => { await held; await route.fulfill(generic401); });
  await page.goto('/');
  await expect(page.locator('#chat-shell')).toBeVisible();
  session.signOut();
  await page.evaluate(() => window.__emitRaw('forced_logout', { reason: 'session_revoked' }));
  await expect(page.locator('#auth-notice')).toHaveText(REVOKED);
  // The request that was in flight comes back as a plain 401 afterwards.
  release();
  await page.waitForTimeout(300);
  await expect(page.locator('#auth-notice')).toHaveText(REVOKED);
  // Spoken, not only shown: the strip was filled with the shell.
  await expect(page.locator('#announcer')).toHaveText(REVOKED);
});

test('#205: "signed out from another device" replaces an earlier generic line', async ({ page }) => {
  const session = await signedInWithStream(page, (route) => route.fulfill(generic401));
  await page.goto('/');
  await expect(page.locator('#auth-notice')).toHaveText(GENERIC);
  session.signOut();
  await page.evaluate(() => window.__emitRaw('forced_logout', { reason: 'session_revoked' }));
  await expect(page.locator('#auth-notice')).toHaveText(REVOKED);
  await expect(page.locator('#announcer')).toHaveText(REVOKED);
});

test('the card is not named by a heading that is hidden in reset or MFA', async ({ page }) => {
  await fixture(page);
  await open(page, '/?reset=1');
  await expect(page.getByRole('region', { name: 'Set a new password' })).toBeVisible();
  await expect(page.locator('.auth-card')).not.toHaveAttribute('aria-labelledby', /.*/);
});

test('step-up completion shows the lockup, and its heading follows the outcome', async ({ page }) => {
  // The page closes itself when done; keep it open to look at it.
  await page.addInitScript(() => { window.close = () => {}; });
  await page.goto('/step-up-complete.php?confirmed=1');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('ChitChat');
  await expect(page.locator('.lockup.lockup-horizontal')).toBeVisible();
  await expect(page.getByRole('heading', { level: 2 })).toHaveText('Confirmed');
  await page.goto('/step-up-complete.php?sign_in_error=The%20provider%20refused.');
  await expect(page.getByRole('heading', { level: 2 })).toHaveText('Not confirmed');
  await expect(page.locator('#step-up-complete-error')).toHaveText('The provider refused.');
  await axe(page, 'step-up completion, failed');
});
