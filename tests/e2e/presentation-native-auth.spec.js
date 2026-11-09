import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// #146: the browser confirmation page for the native app handoff (spec: issue
// comment 6732535902, amended for CCM's review). Each phase is rendered by the
// real NativeAuthPage through a PHP fixture outside public/ (no production
// fixture route), then served in place of /native-auth.php so the page's own
// stylesheets load from the PHP server. Presentation only: the flow itself is
// covered by the PHP tests.
const fixtureScript = path.join(path.dirname(fileURLToPath(import.meta.url)), 'fixtures', 'native-auth-page.php');
const NOW = '2026-11-09T10:00:00Z';
const CODE = 'c0de-SECRET-one-use';
const RETURN_URL = `de.networkxxiii.chitchat:/native-auth?code=${CODE}&transaction_id=tx-1&state=st-1`;
const base = '/api/v1/native-auth/browser/';
const none = { continue: null, approve: null, cancel: null, restart: null };

function view(overrides = {}) {
  return {
    installation_name: 'Hearth', app_name: 'ChitChat for Android', purpose: 'login', provider: { label: 'Google' },
    expires_at: '2026-11-09T10:09:00Z', bound_account: null, browser_account: null, phase: 'entry', outcome: null,
    csrf_token: 'csrf-fixture', actions: none, return_url: null, cancel_return_url: null, status_url: '/native-auth.php',
    ...overrides,
  };
}

const jamie = { id: 5, username: 'Jamie' };
const PHASES = {
  entry: view({ purpose: 'link', bound_account: jamie, actions: { ...none, continue: `${base}continue.php`, cancel: `${base}cancel.php` } }),
  verifyingParked: view({ phase: 'verifying', actions: { ...none, continue: `${base}resume.php`, cancel: `${base}cancel.php` } }),
  verifying: view({ phase: 'verifying', actions: { ...none, restart: `${base}restart.php`, cancel: `${base}cancel.php` } }),
  consent: view({ phase: 'consent', purpose: 'picture', bound_account: jamie, actions: { ...none, approve: `${base}approve.php`, cancel: `${base}cancel.php` } }),
  accountMismatch: view({ phase: 'account_mismatch', purpose: 'step_up', bound_account: jamie, browser_account: { username: 'Rowan' },
    actions: { ...none, restart: `${base}restart.php`, cancel: `${base}cancel.php` } }),
  identityMismatch: view({ phase: 'identity_mismatch', purpose: 'link', bound_account: jamie,
    actions: { ...none, restart: `${base}restart.php`, cancel: `${base}cancel.php` } }),
  staged: view({ phase: 'staged', return_url: RETURN_URL }),
  stagedNoLink: view({ phase: 'staged', purpose: 'link', bound_account: jamie, return_url: null, status_url: null }),
  usedLinkCompleted: view({ phase: 'used', purpose: 'link', outcome: 'completed' }),
  usedPicture: view({ phase: 'used', purpose: 'picture', outcome: 'completed' }),
  usedLoginUnknown: view({ phase: 'used', purpose: 'login', outcome: 'unknown' }),
  usedLoginCompleted: view({ phase: 'used', purpose: 'login', outcome: 'completed' }),
  usedMfa: view({ phase: 'used', outcome: 'pending_mfa' }),
  usedSignUp: view({ phase: 'used', purpose: 'sign_up', outcome: 'pending_sign_up' }),
  used: view({ phase: 'used' }),
  cancelled: view({ phase: 'cancelled' }),
  expired: view({ phase: 'expired' }),
  disabled: view({ phase: 'disabled', provider: { label: '' } }),
};

function render(model) {
  return execFileSync('php', [fixtureScript], { input: JSON.stringify({ view: model, now: NOW }), encoding: 'utf8' });
}

/** Serves the rendered phase as /native-auth.php and records every non-GET request. */
async function open(page, model) {
  const html = render(model);
  const posts = [];
  page.on('request', (request) => { if (request.method() !== 'GET') posts.push(request.url()); });
  await page.route('**/native-auth.php*', (route) => route.fulfill({
    contentType: 'text/html; charset=utf-8', body: html,
    headers: { 'Cache-Control': 'no-store', 'Referrer-Policy': 'no-referrer' },
  }));
  await page.goto('/native-auth.php');
  await expect(page.locator('#native-auth-heading')).toBeVisible();
  return { html, posts };
}

const heading = (page) => page.locator('#native-auth-heading');

test('entry: says who wants what, offers Continue and Cancel as POSTs, and opening it posts nothing', async ({ page }) => {
  const { posts } = await open(page, PHASES.entry);
  await expect(page.locator('h1')).toHaveText('Hearth');
  await expect(heading(page)).toHaveText('ChitChat for Android wants to connect your Google account to Jamie.');
  await expect(page.locator('.native-auth-details')).toContainText('Expires');
  await expect(page.locator('time')).toHaveText('in 9 min');
  const forms = page.locator('.native-auth-actions form');
  await expect(forms).toHaveCount(2);
  await expect(forms.nth(0)).toHaveAttribute('method', 'post');
  await expect(forms.nth(0).getByRole('button')).toHaveText('Continue with Google');
  await expect(forms.nth(1).getByRole('button')).toHaveText('Cancel');
  await expect(forms.nth(0).locator('input[name="csrf_token"]')).toHaveValue('csrf-fixture');
  // Nothing is focused for the person, and opening the page changes nothing.
  expect(await page.evaluate(() => document.activeElement === document.body)).toBe(true);
  await page.waitForLoadState('networkidle');
  expect(posts).toEqual([]);
  // The first Tab reaches the primary action, then Cancel.
  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', { name: 'Continue with Google' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', { name: 'Cancel' })).toBeFocused();
});

test('consent: Approve is offered but never focused first', async ({ page }) => {
  await open(page, PHASES.consent);
  await expect(heading(page)).toHaveText('Allow ChitChat for Android to use your Google profile picture for Jamie?');
  await expect(page.getByText('Verified with Google.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Approve' })).not.toBeFocused();
  expect(await page.locator('[autofocus]').count()).toBe(0);
});

test('a parked provider return offers Continue; an unfinished one offers Try again', async ({ page }) => {
  await open(page, PHASES.verifyingParked);
  await expect(heading(page)).toHaveText('Back from Google.');
  await expect(page.locator('form[action$="resume.php"]').getByRole('button')).toHaveText('Continue');
  await page.unrouteAll();
  await open(page, PHASES.verifying);
  await expect(heading(page)).toHaveText('Waiting for Google…');
  await expect(page.locator('form[action$="restart.php"]').getByRole('button')).toHaveText('Try again');
});

test('mismatches explain without changing the target', async ({ page }) => {
  await open(page, PHASES.accountMismatch);
  await expect(heading(page)).toHaveText('This browser is signed in as Rowan, but the app asked for Jamie.');
  await expect(page.getByRole('button', { name: 'Continue as Jamie' })).toBeVisible();
  await page.unrouteAll();
  await open(page, PHASES.identityMismatch);
  await expect(heading(page)).toHaveText('That Google account isn’t connected to Jamie.');
  await expect(page.getByRole('button', { name: 'Try another Google account' })).toBeVisible();
});

test('staged: "Return to the ChitChat app", not signed in yet, the code only in that href, no auto-navigation', async ({ page }) => {
  const { html } = await open(page, PHASES.staged);
  await expect(heading(page)).toHaveText('Return to the app to finish.');
  await expect(page.getByText('Return requested, not signed in yet.')).toBeVisible();
  const link = page.getByRole('link', { name: 'Return to the ChitChat app' });
  await expect(link).toHaveAttribute('href', RETURN_URL);
  await expect(page.getByText('Didn’t open? You can close this tab and go back to the app.')).toBeVisible();
  // The code appears exactly once in the document: inside that one href.
  expect(html.split(CODE).length - 1).toBe(1);
  expect(await page.locator('body').innerText()).not.toContain(CODE);
  const elsewhere = await page.evaluate((code) => [...document.querySelectorAll('*')].flatMap((node) => [...node.attributes]
    .filter((attribute) => attribute.value.includes(code) && !(node.matches('a.native-auth-return') && attribute.name === 'href'))
    .map((attribute) => `${node.tagName}@${attribute.name}`)), CODE);
  expect(elsewhere).toEqual([]);
  // No timer, no refresh, no script but the theme.
  expect(html).not.toMatch(/http-equiv\s*=\s*["']?refresh/i);
  expect(await page.locator('script').evaluateAll((nodes) => nodes.map((node) => node.getAttribute('src')))).toEqual(['/assets/js/theme.js']);
  await expect(page.getByRole('link', { name: 'Check again' })).toHaveAttribute('href', '/native-auth.php');
});

test('staged without a return link still gives code-free directions', async ({ page }) => {
  await open(page, PHASES.stagedNoLink);
  await expect(page.getByRole('link', { name: 'Return to the ChitChat app' })).toHaveCount(0);
  await expect(page.getByText('You can close this tab and go back to the app.')).toBeVisible();
  await expect(page.getByText(/not signed in/)).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Check again' })).toHaveCount(0);
});

test('outcomes stay as narrow as the server recorded; sign-in never claims success', async ({ page }) => {
  const expectations = [
    ['usedLinkCompleted', 'Your Google account is connected.'],
    ['usedPicture', 'Picture ready in the app.'],
    ['usedLoginUnknown', 'Return requested.'],
    ['usedLoginCompleted', 'Return requested.'],
    ['usedMfa', 'Almost there: confirm it’s you in the app.'],
    ['usedSignUp', 'Finish creating your account in the app.'],
    ['used', 'This link was already used.'],
    ['cancelled', 'Cancelled.'],
    ['expired', 'This link has expired.'],
    ['disabled', 'This request can’t continue here.'],
  ];
  for (const [name, text] of expectations) {
    await page.unrouteAll();
    await open(page, PHASES[name]);
    await expect(heading(page), name).toHaveText(text);
    expect(await page.locator('body').innerText(), name).not.toMatch(/^(you’re |you are )?signed in|signed you in/im);
  }
  await page.unrouteAll();
  await open(page, PHASES.usedLoginCompleted);
  await expect(page.getByText('Check the app to see whether you’re signed in.')).toBeVisible();
  await expect(page.locator('form, .native-auth-details')).toHaveCount(0);
});

test('values are escaped', async ({ page }) => {
  await open(page, view({ phase: 'account_mismatch', purpose: 'step_up', bound_account: { id: 9, username: '<img src=x onerror=alert(1)>' },
    browser_account: { username: '"Rowan"&' }, installation_name: '<b>Hearth</b>', actions: { ...none, cancel: `${base}cancel.php` } }));
  await expect(page.locator('h1')).toHaveText('<b>Hearth</b>');
  await expect(heading(page)).toContainText('<img src=x onerror=alert(1)>');
  expect(await page.locator('main img').count()).toBe(1);
});

test('the real page sends no-store and no-referrer', async ({ request }) => {
  const response = await request.get('/native-auth.php');
  expect(response.headers()['cache-control']).toContain('no-store');
  expect(response.headers()['referrer-policy']).toBe('no-referrer');
  expect(response.headers()['x-robots-tag']).toContain('noindex');
});

for (const [theme, width] of [['light', 360], ['dark', 1280]]) {
  test(`${theme} at ${width}px: every phase passes axe, reflows, and loads nothing from elsewhere`, async ({ page }) => {
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await page.setViewportSize({ width, height: 800 });
    const external = [];
    page.on('request', (request) => {
      const url = new URL(request.url());
      if (url.hostname !== '127.0.0.1' && url.hostname !== 'localhost') external.push(request.url());
    });
    for (const [name, model] of Object.entries(PHASES)) {
      await page.unrouteAll();
      await open(page, model);
      expect(await page.evaluate(() => document.documentElement.scrollWidth), name).toBeLessThanOrEqual(width);
      const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
      expect(result.violations, name).toEqual([]);
    }
    expect(external).toEqual([]);
  });
}

// #211: an installation's own icon replaces the Lantern favicon and mark; null keeps Lantern.
test('the installation icon replaces the favicon and mark when set', async ({ page }) => {
  await page.route('**/installation-icon.php*', (route) => route.fulfill({ contentType: 'image/png',
    body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64') }));
  const icon = { favicon_url: '/installation-icon.php?revision=r&variant=favicon', mark_url: '/installation-icon.php?revision=r&variant=icon_192',
    apple_touch_url: '/installation-icon.php?revision=r&variant=apple_touch' };
  const { html } = await open(page, view({ ...PHASES.entry, installation_icon: icon }));
  await expect(page.locator('link[rel="icon"]')).toHaveAttribute('href', icon.favicon_url);
  await expect(page.locator('.native-auth-mark')).toHaveAttribute('src', icon.mark_url);
  await expect(page.locator('.native-auth-mark')).toHaveCSS('border-radius', /px|%/);
  expect(html).not.toContain('/favicon.svg');
  expect(html).not.toContain(icon.apple_touch_url);
  await page.unrouteAll();
  await open(page, view({ ...PHASES.entry, installation_icon: null }));
  await expect(page.locator('link[rel="icon"]')).toHaveAttribute('href', '/favicon.svg');
  await expect(page.locator('.native-auth-mark')).toHaveAttribute('src', '/favicon.svg');
});
