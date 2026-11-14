import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// #219: "Report an accessibility problem" on the sign-in screens and the You page, from
// session.php's accessibility_feedback (hidden when it's null), and the Operational settings
// card that chooses where it leads. Presentation only: the real pages against intercepted APIs.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const PROJECT = 'https://github.com/Thiesi/ChitChat/issues/new?template=accessibility.yml';
const member = { id: 7, username: 'Jamie', roles: ['member'], session_version: 1 };
const root = { id: 1, username: 'Root', roles: ['super_admin'], session_version: 1 };
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

async function fixture(page, { user = null, feedback = { url: PROJECT }, saved = { enabled: true, url: null }, update = null } = {}) {
  const posts = [];
  let current = { ...saved };
  const resolved = () => ({ ...current, default_url: PROJECT, effective_url: current.enabled ? (current.url ?? PROJECT) : null });
  await page.route('**/installation-icon.php*', (route) => route.fulfill({ contentType: 'image/png', body: PNG }));
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace('/api/v1/', '');
    if (request.method() === 'POST') posts.push({ path, body: request.postDataJSON?.() ?? null });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path === 'admin/settings/accessibility-feedback/get.php') return route.fulfill({ json: { accessibility_feedback: resolved() } });
    if (path === 'admin/settings/accessibility-feedback/update.php') {
      if (update) return route.fulfill(update(request));
      const body = request.postDataJSON();
      current = { enabled: body.enabled, url: body.url };
      return route.fulfill({ json: { accessibility_feedback: resolved() } });
    }
    const responses = {
      'session.php': { user, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [], guest_access: true, accessibility_feedback: feedback },
      'users/profile.php': user ? { profile: { id: user.id, username: user.username, member_since: '2025-03-14T09:00:00Z', has_avatar: false }, avatars_available: true } : {},
      'admin/settings/get.php': { settings: { registration_enabled: true, guest_access_enabled: false, mfa_required_for_admin_roles: false, audit_retention_days: 365, deleted_attachment_retention_days: 30, deleted_room_grace_days: 30, direct_message_retention_days: 0, login_attempt_retention_days: 30, orphan_attachment_grace_hours: 24, realtime_event_retention_hours: 24, room_message_retention_days: 0, updated_at: '2026-11-01T10:00:00Z' } },
      'admin/settings/lockdown/get.php': { lockdown: { enabled: false, custom_message: null, since: null } },
      'admin/settings/application-name/get.php': { application_name: { default: 'ChitChat', override: null } },
      'admin/settings/registration-protection/get.php': { registration_protection: { overrides: { rate_limit_max_attempts: null, rate_limit_window_seconds: null, min_fill_seconds: null, proof_of_work_bits: null }, defaults: { rate_limit_max_attempts: 5, rate_limit_window_seconds: 3600, min_fill_seconds: 3, proof_of_work_bits: 16 }, effective: { rate_limit_max_attempts: 5, rate_limit_window_seconds: 3600, min_fill_seconds: 3, proof_of_work_bits: 16 } } },
      'admin/policies.php': { policies: { rules: { source: 'none', markdown: '', url: null, version: 0, updated_at: null }, privacy: { source: 'none', markdown: '', url: null, version: 0, updated_at: null }, legal_notice: { url: null } } },
      'policies.php': { policies: { rules: { source: 'none', version: 0 }, privacy: { source: 'none' } } },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'account/mfa/status.php': { mfa: { enabled: false, available: false, credentials: [] } },
      'account/identities/list.php': { providers: [], identities: [], has_password: true },
      'account/sessions/list.php': { sessions: [], maximum_sessions: 50 },
      'rooms/list.php': { rooms: [] },
      'direct-messages/conversations.php': { conversations: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return posts;
}

const signInLink = (page) => page.locator('#auth-shell .powered-by').getByRole('link', { name: /Report an accessibility problem/ });
const youLink = (page) => page.getByRole('navigation', { name: 'Settings' }).getByRole('link', { name: /Report an accessibility problem/ });

test.describe('the link', () => {
  test('signed out, the sign-in footer opens the project’s form on GitHub in a new tab', async ({ page }) => {
    await fixture(page);
    await page.goto('/');
    const link = signInLink(page);
    await expect(link).toBeVisible();
    await expect(link).toHaveAccessibleName(/^Report an accessibility problem \(opens GitHub\)\s*, in a new tab$/);
    await expect(link).toHaveAttribute('href', PROJECT);
    await expect(link).toHaveAttribute('target', '_blank');
    await expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    // It sits before the attribution, and is a comfortable target.
    // innerText also carries the visually hidden ", in a new tab".
    expect(await page.locator('#auth-shell .powered-by').innerText()).toMatch(/^Report an accessibility problem \(opens GitHub\)(, in a new tab)?\s*·\s*Powered by ChitChat$/);
    expect((await link.boundingBox()).height).toBeGreaterThanOrEqual(44);
  });

  test('an operator’s mailbox says email and stays in this tab', async ({ page }) => {
    await fixture(page, { feedback: { url: 'mailto:access@example.org' } });
    await page.goto('/');
    const link = signInLink(page);
    await expect(link).toHaveAccessibleName('Report an accessibility problem (email)');
    await expect(link).toHaveAttribute('href', 'mailto:access@example.org');
    await expect(link).not.toHaveAttribute('target', /.+/);
  });

  test('an operator’s page names its host', async ({ page }) => {
    await fixture(page, { feedback: { url: 'https://help.example.org/accessibility' } });
    await page.goto('/');
    await expect(signInLink(page)).toHaveAccessibleName(/^Report an accessibility problem \(opens help\.example\.org\)\s*, in a new tab$/);
  });

  test('switched off, there’s no link and no stray separator', async ({ page }) => {
    await fixture(page, { feedback: null });
    await page.goto('/');
    await expect(page.locator('#auth-shell .powered-by')).toBeVisible();
    await expect(page.locator('#auth-shell [data-accessibility-feedback]')).toBeHidden();
    expect((await page.locator('#auth-shell .powered-by').innerText()).trim()).toBe('Powered by ChitChat');
  });

  test('signed in, the You list ends with it', async ({ page }) => {
    await fixture(page, { user: member });
    await page.goto('/account.php');
    await expect(youLink(page)).toBeVisible();
    await expect(youLink(page)).toHaveAccessibleName(/^Report an accessibility problem \(opens GitHub\)\s*, in a new tab$/);
    await expect(youLink(page)).toHaveAttribute('href', PROJECT);
    expect((await youLink(page).boundingBox()).height).toBeGreaterThanOrEqual(44);
  });

  test('signed in and switched off, the You list has no link', async ({ page }) => {
    await fixture(page, { user: member, feedback: null });
    await page.goto('/account.php');
    await expect(page.getByRole('navigation', { name: 'Settings' }).getByRole('link', { name: 'Profile', exact: true })).toBeVisible();
    await expect(page.locator('.you-feedback')).toBeHidden();
  });
});

const card = (page) => page.locator('#accessibility-feedback-settings');
const radio = (page, name) => card(page).getByRole('radio', { name });
const save = (page) => card(page).getByRole('button', { name: 'Save accessibility feedback' });
const address = (page) => card(page).getByRole('textbox', { name: /Address/ });
const updates = (posts) => posts.filter((entry) => entry.path === 'admin/settings/accessibility-feedback/update.php').map((entry) => entry.body);

async function openSettings(page) {
  await page.goto('/admin-settings.php');
  await expect(card(page)).toBeVisible();
}

test.describe('Operational settings', () => {
  test('three plain choices in a named group, the saved one chosen, nothing to save yet', async ({ page }) => {
    await fixture(page, { user: root });
    await openSettings(page);
    await expect(card(page).getByRole('heading', { name: 'Accessibility feedback' })).toBeVisible();
    await expect(card(page).getByRole('group', { name: 'Where the link leads' })).toBeVisible();
    await expect(radio(page, 'The ChitChat project (GitHub)')).toBeChecked();
    await expect(radio(page, 'My own address')).not.toBeChecked();
    await expect(radio(page, 'Don’t show a link')).not.toBeChecked();
    await expect(address(page)).toBeHidden();
    await expect(card(page).locator('#feedback-current')).toHaveText('Now: the link opens the ChitChat project’s form on GitHub.');
    await expect(save(page)).toBeDisabled();
  });

  test('my own address saves {enabled, url}; off keeps the saved address; the project sends null', async ({ page }) => {
    const posts = await fixture(page, { user: root });
    await openSettings(page);

    await radio(page, 'My own address').check();
    await expect(address(page)).toBeVisible();
    await expect(card(page).locator('#feedback-status')).toHaveText('Not saved yet.');
    await address(page).fill('mailto:access@example.org');
    await save(page).click();
    await expect(card(page).locator('#feedback-status')).toHaveText('Saved. The link leads there from the next page load.');
    await expect(card(page).locator('#feedback-current')).toHaveText('Now: the link writes an email to access@example.org.');

    await radio(page, 'Don’t show a link').check();
    await expect(address(page)).toBeHidden();
    await save(page).click();
    await expect(card(page).locator('#feedback-status')).toHaveText('Saved. No link is shown from the next page load.');
    await expect(card(page).locator('#feedback-current')).toHaveText('Now: no link is shown.');

    // Back on, the kept address is still there.
    await radio(page, 'My own address').check();
    await expect(address(page)).toHaveValue('mailto:access@example.org');

    await radio(page, 'The ChitChat project (GitHub)').check();
    await save(page).click();
    await expect(card(page).locator('#feedback-current')).toHaveText('Now: the link opens the ChitChat project’s form on GitHub.');

    expect(updates(posts)).toEqual([
      { enabled: true, url: 'mailto:access@example.org' },
      { enabled: false, url: 'mailto:access@example.org' },
      { enabled: true, url: null },
    ]);
  });

  test('an empty address is caught here; the server’s message is shown under the field and tied to it', async ({ page }) => {
    const posts = await fixture(page, {
      user: root,
      update: () => ({ status: 400, json: { error: { code: 'validation_error', message: 'The address must start with https:// or mailto:.' } } }),
    });
    await openSettings(page);
    await radio(page, 'My own address').check();
    await save(page).click();
    await expect(card(page).locator('#feedback-error')).toHaveText('Enter an address that starts with https:// or mailto:.');
    await expect(address(page)).toBeFocused();
    expect(updates(posts)).toEqual([]);

    await address(page).fill('http://example.org');
    await save(page).click();
    await expect(card(page).locator('#feedback-error')).toHaveText('The address must start with https:// or mailto:.');
    await expect(address(page)).toHaveAttribute('aria-invalid', 'true');
    await expect(address(page)).toHaveAccessibleDescription('The address must start with https:// or mailto:.');
    await expect(address(page)).toBeFocused();
    // Typing again clears it.
    await address(page).fill('https://example.org');
    await expect(card(page).locator('#feedback-error')).toHaveText('');
    await expect(address(page)).not.toHaveAttribute('aria-invalid', /.+/);
  });

  for (const theme of ['light', 'dark']) {
    test(`${theme}: the card passes axe with the address open`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: theme, reducedMotion: 'reduce' });
      await fixture(page, { user: root, saved: { enabled: true, url: 'https://help.example.org/accessibility' } });
      await openSettings(page);
      await expect(radio(page, 'My own address')).toBeChecked();
      await expect(address(page)).toHaveValue('https://help.example.org/accessibility');
      await page.mouse.move(0, 0);
      const result = await new AxeBuilder({ page }).include('#accessibility-feedback-settings').withTags(WCAG).analyze();
      expect(result.violations).toEqual([]);
    });
  }
});

for (const theme of ['light', 'dark']) {
  test(`${theme}: the sign-in footer passes axe with the link`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: theme, reducedMotion: 'reduce' });
    await fixture(page);
    await page.goto('/');
    await expect(signInLink(page)).toBeVisible();
    await page.mouse.move(0, 0);
    expect((await new AxeBuilder({ page }).include('#auth-shell .powered-by').withTags(WCAG).analyze()).violations).toEqual([]);
  });
}
