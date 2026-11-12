import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// S5a (#171; spec 6731478671, addendum 6826886672): You as sections. The list
// pane has the person and six sections (Stewarding first for staff, Sign out
// last); the main pane shows the chosen one. Each section has an address,
// old anchors and deep links land in the right section, and on phones the
// list comes first and a section pushes over it. The parity table: every
// card sits in its section. Presentation only: the real page against
// intercepted APIs.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// Push is configured so Alerts shows its settings; a registered service worker
// would answer requests past page.route (WebKit), so none is allowed here.
test.use({ serviceWorkers: 'block' });
const member = {
  id: 1, username: 'Jamie', roles: ['user'], session_version: 1, guest: false,
  rules: { accepted_version: 1, accepted_at: '2026-10-01T10:00:00Z', acceptance_required: false },
  recovery_key: { exists: true, created_at: '2026-11-01T10:00:00Z' }, username_change: null,
};
const guest = { id: 90, username: 'Guest 4821', roles: ['guest'], guest: true, session_version: 1 };
const admin = { ...member, roles: ['admin'] };
const mfaOff = {
  enabled: false, available: true, credentials: [], recovery_codes_remaining: 0, usable_methods: [],
  totp: { enabled: false, available: true, created_at: null, last_used_at: null },
};

async function fixture(page, { user = member } = {}) {
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname.replace('/api/v1/', '');
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    const responses = {
      'session.php': {
        user, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [], guest_access: true,
        capabilities: { totp: true, signed_in_sessions: true },
        web_push: { enabled: true, vapid_public_key: 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U' },
      },
      'users/profile.php': { profile: { id: user.id, username: user.username, member_since: '2025-03-14T09:00:00Z', has_avatar: false }, avatars_available: true },
      'account/mfa/status.php': { mfa: mfaOff },
      'account/identities/list.php': { providers: [], identities: [], has_password: true },
      'account/sessions/list.php': { sessions: [], maximum_sessions: 50 },
      'push/preferences.php': { preferences: { mentioned_push_enabled: true, pinged_push_enabled: true, direct_message_push_enabled: false, quiet_hours: null }, devices: [] },
      'policies.php': { policies: { rules: { source: 'none', version: 0 }, privacy: { source: 'none' } } },
      'rooms/list.php': { rooms: [] },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
}

const list = (page) => page.getByRole('region', { name: 'You', exact: true });
const nav = (page) => page.getByRole('navigation', { name: 'Settings' });
const sectionLink = (page, name) => nav(page).getByRole('link', { name, exact: true });
const title = (page) => page.locator('#you-section-title');
const bar = (page) => page.getByRole('navigation', { name: 'Main' });

async function open(page, width = 1280, path = '/account.php') {
  await page.setViewportSize({ width, height: 900 });
  await page.goto(path);
  await expect(page.locator('#account-loading')).toBeHidden();
}

async function axe(page, label) {
  await page.mouse.move(0, 0);
  await page.waitForTimeout(250);
  const result = await new AxeBuilder({ page }).withTags(WCAG).analyze();
  expect(result.violations, `${label}: ${result.violations.map((violation) => `${violation.id} ${violation.nodes.map((node) => node.target).join(' ')}`).join('; ')}`).toEqual([]);
}

// ---- Desktop ----

test('the list pane: the person, six sections, Sign out last; Profile opens by default', async ({ page }) => {
  await fixture(page);
  await open(page);
  await expect(list(page).getByRole('heading', { level: 1, name: 'You' })).toBeVisible();
  await expect(page.locator('#account-identity')).toHaveText('Signed in as Jamie');
  await expect(page.locator('#you-member-since')).toHaveText(/^Member since .*2025/);
  await expect(nav(page).getByRole('link')).toHaveText(['Profile', 'Alerts', 'Appearance', 'Sign-in and security', 'Privacy and data', 'Account']);
  await expect(sectionLink(page, 'Profile')).toHaveAttribute('aria-current', 'page');
  await expect(title(page)).toHaveText('Profile');
  // Sign out comes after the sections.
  const order = await list(page).evaluate((pane) => {
    const signOut = pane.querySelector('#logout-button');
    const lastLink = [...pane.querySelectorAll('.you-section-link')].at(-1);
    return Boolean(lastLink.compareDocumentPosition(signOut) & Node.DOCUMENT_POSITION_FOLLOWING);
  });
  expect(order).toBe(true);
  await expect(list(page).getByRole('button', { name: 'Sign out', exact: true })).toBeVisible();
  // The address stays clean until a section is chosen.
  expect(new URL(page.url()).hash).toBe('');
});

const PARITY = [
  ['Profile', ['Name', 'Profile picture']],
  ['Alerts', ['What should alert you?', 'Quiet hours', 'This browser', 'Devices with alerts on', 'Sounds on this device']],
  ['Appearance', ['Theme and colours', 'How dates and times look', 'Typing indicator']],
  ['Sign-in and security', ['Password and recovery', 'Recovery key', 'Passkeys and recovery codes', 'Where you’re signed in']],
  ['Privacy and data', ['Download your personal data']],
  ['Account', ['Close your account']],
];

test('parity: every card sits in its section, and only the chosen section shows', async ({ page }) => {
  await fixture(page);
  await open(page);
  for (const [section, headings] of PARITY) {
    await sectionLink(page, section).click();
    await expect(title(page), section).toHaveText(section);
    await expect(title(page)).toBeFocused();
    await expect(sectionLink(page, section)).toHaveAttribute('aria-current', 'page');
    await expect(nav(page).locator('[aria-current]')).toHaveCount(1);
    for (const heading of headings) {
      await expect(page.locator('#account-shell').getByRole('heading', { name: heading, exact: true }), `${section}: ${heading}`).toBeVisible();
    }
    // The other sections' cards are out of view.
    for (const [other, otherHeadings] of PARITY) {
      if (other === section) continue;
      await expect(page.locator('#account-shell').getByRole('heading', { name: otherHeadings[0], exact: true }), `${section} hides ${other}`).toBeHidden();
    }
  }
  // The address names the chosen section, without history entries of its own.
  await expect(page).toHaveURL(/#account$/);
});

test('Profile shows the username and when you joined; a required change says so', async ({ page }) => {
  await fixture(page, { user: { ...member, username_change: { required: true, required_at: '2026-11-10T10:00:00Z', reason: 'Offensive', sign_in_with_previous_username: true } } });
  await open(page);
  await expect(page.locator('#you-username')).toHaveText('Jamie');
  await expect(page.locator('#you-member-since-full')).toHaveText(/2025/);
  await expect(page.getByRole('link', { name: 'Choose a new username' })).toHaveAttribute('href', '/choose-username.php');
});

test('the typing indicator is a switch', async ({ page }) => {
  await fixture(page);
  await open(page, 1280, '/account.php#appearance');
  await expect(page.getByRole('switch', { name: /Show when I’m typing/ })).toBeVisible();
});

for (const [address, section] of [
  ['#alerts', 'Alerts'],
  ['#security', 'Sign-in and security'],
  ['#appearance-heading', 'Appearance'],
  ['#account-closure-heading', 'Account'],
  ['?picture=ready', 'Profile'],
  ['?recovery=create', 'Sign-in and security'],
  ['?connected=google', 'Sign-in and security'],
  // A malformed escape names no section (it must not throw and leave the pane blank).
  ['#%E0', 'Profile'],
]) {
  test(`the address ${address} opens ${section}`, async ({ page }) => {
    await fixture(page);
    await open(page, 1280, `/account.php${address}`);
    await expect(title(page)).toHaveText(section);
    await expect(sectionLink(page, section)).toHaveAttribute('aria-current', 'page');
  });
}

test('a modified click on a section opens it in a new tab at its address', async ({ page }) => {
  await fixture(page);
  await open(page);
  const opened = page.context().waitForEvent('page');
  await sectionLink(page, 'Alerts').click({ modifiers: ['ControlOrMeta'] });
  const tab = await opened;
  await expect.poll(() => tab.url()).toMatch(/\/account\.php#alerts$/);
  await tab.close();
  await expect(title(page)).toHaveText('Profile');
});

test('staff see Stewarding as the first row', async ({ page }) => {
  await fixture(page, { user: admin });
  await open(page);
  const steward = page.locator('#you-steward-card');
  await expect(steward).toBeVisible();
  await expect(steward.getByRole('link', { name: 'Looking after this server' })).toHaveAttribute('href', '/admin.php');
  expect(await page.locator('.you-sections > li:not([hidden]):not(.hidden)').first().getAttribute('id')).toBe('you-steward-card');
});

test('guests: the visit card, Appearance only, and Leave', async ({ page }) => {
  await fixture(page, { user: guest });
  await open(page);
  await expect(page.getByRole('heading', { name: 'Want to stay?' })).toBeVisible();
  await expect(nav(page).getByRole('link')).toHaveText(['Appearance']);
  await expect(title(page)).toHaveText('Appearance');
  await expect(page.getByRole('heading', { name: 'Theme and colours' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Typing indicator' })).toBeHidden();
  await expect(list(page).getByRole('button', { name: 'Leave' })).toBeVisible();
});

// ---- Phones ----

test('phones: the list first; a section pushes over it, and Back returns to its row', async ({ page }) => {
  await fixture(page);
  await open(page, 360);
  await expect(list(page)).toBeVisible();
  await expect(page.locator('#account-shell')).toBeHidden();
  await expect(bar(page)).toBeVisible();
  // Nothing is open yet, so no row claims to be current.
  await expect(nav(page).locator('[aria-current]')).toHaveCount(0);
  // Sign out is right there on the list: You, then Sign out.
  await expect(list(page).getByRole('button', { name: 'Sign out', exact: true })).toBeVisible();

  await sectionLink(page, 'Alerts').click();
  await expect(title(page)).toBeFocused();
  await expect(title(page)).toHaveText('Alerts');
  await expect(list(page)).toBeHidden();
  await expect(bar(page)).toBeHidden();
  await expect(page.getByRole('heading', { name: 'What should alert you?' })).toBeVisible();

  await page.getByRole('button', { name: 'Back to you' }).click();
  await expect(list(page)).toBeVisible();
  await expect(sectionLink(page, 'Alerts')).toBeFocused();
  await expect(bar(page)).toBeVisible();

  // The browser's back does the same.
  await sectionLink(page, 'Account').click();
  await expect(title(page)).toHaveText('Account');
  await page.goBack();
  await expect(list(page)).toBeVisible();
});

test('phones: a section address starts on that section', async ({ page }) => {
  await fixture(page);
  await open(page, 360, '/account.php#security');
  await expect(title(page)).toHaveText('Sign-in and security');
  await expect(list(page)).toBeHidden();
  await expect(page.getByRole('heading', { name: 'Password and recovery' })).toBeVisible();
});

// ---- axe and reflow ----

for (const [scheme, theme] of [['lantern', 'light'], ['lantern', 'dark'], ['dusk', 'dark']]) {
  test(`${scheme} ${theme}: You passes axe at 1280 (each section) and 360 (list and a section), and reflows at 320`, async ({ page }) => {
    await page.addInitScript(([chosenScheme, chosenTheme]) => {
      localStorage.setItem('chitchat-theme', chosenTheme);
      localStorage.setItem('chitchat-scheme', chosenScheme);
    }, [scheme, theme]);
    await fixture(page);
    await open(page, 1280);
    for (const [section] of PARITY) {
      await sectionLink(page, section).click();
      await expect(title(page)).toHaveText(section);
      await axe(page, `1280 ${section}`);
    }
    await open(page, 360);
    await axe(page, '360 list');
    await sectionLink(page, 'Sign-in and security').click();
    await expect(title(page)).toHaveText('Sign-in and security');
    await axe(page, '360 security');
    await page.setViewportSize({ width: 320, height: 700 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  });
}
