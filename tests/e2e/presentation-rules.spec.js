import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// Community rules and privacy policy (#160), presentation only: real PHP
// templates and browser modules against intercepted API responses.
const viewer = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const peer = { id: 2, username: 'Rowan' };
const rooms = [{
  id: 1, name: 'The living room', key: 'room-0', visibility: 'public', member_role: 'member',
  minimum_age: 0, inactivity_timeout_seconds: 0, info_line: 'A place for everyday conversations.',
}];
const messages = [['Rowan', 'Anyone else taking the slow route through Sunday?'], ['Jamie', 'Coffee and no plans.']]
  .map(([username, body], index) => ({
    id: index + 1, room_id: 1, user_id: username === 'Jamie' ? 1 : 2, username, body, type: 'text',
    deleted: false, mentions: [], created_at: `2026-11-08T10:0${index}:00Z`, reactions: [],
  }));

const policies = {
  rules: { url: 'http://127.0.0.1/rules.php', version: 3, updated_at: '2026-11-08T09:00:00Z' },
  privacy: { url: 'http://127.0.0.1/privacy.php', updated_at: '2026-11-01T09:00:00Z' },
  legal_notice: null,
};
const rulesHtml = '<h2>Be kind</h2><p>Treat people the way you would at a dinner table.</p><h2>No self-promotion</h2><ul><li>Share links that help the conversation.</li><li>Ask a moderator before advertising.</li></ul>';

function accepted(version) {
  return { accepted_version: version, accepted_at: '2026-10-01T12:00:00Z', acceptance_required: false };
}

async function fixture(page, { user = viewer, sessionPolicies = policies, overrides = {}, requests = [] } = {}) {
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname.replace('/api/v1/', '');
    if (request.method() === 'POST') requests.push({ path, body: request.postDataJSON?.() ?? null });
    if (path === 'events/stream.php') {
      await route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
      return;
    }
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path in overrides) {
      const value = typeof overrides[path] === 'function' ? overrides[path](request) : overrides[path];
      if (value?.status) return route.fulfill({ status: value.status, json: value.json });
      return route.fulfill({ json: value });
    }
    const responses = {
      'session.php': {
        user, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [],
        guest_access: true, policies: sessionPolicies,
      },
      'registration-challenge.php': { challenge: null },
      'guest/challenge.php': { challenge: null },
      'rooms/list.php': { rooms },
      'rooms/messages.php': { messages },
      'rooms/message-mutations.php': { messages: messages.map((message) => ({ ...message, can_edit: message.user_id === 1, can_delete: message.user_id === 1 })) },
      'attachments/metadata.php': { attachments: [] },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [viewer, peer].map((person) => ({ ...person, idle_seconds: 0 })) },
      'rooms/mentionable-users.php': { users: [peer] },
      'rooms/pings.php': { pings: [] },
      'typing.php': { signalled: true },
      'rooms/read.php': { last_read_message_id: 2 },
      'users/profile.php': { avatars_available: true, profile: { id: 1, username: 'Jamie', member_since: '2026-07-01T10:00:00Z', badge: null, has_avatar: false, avatar_version: null, can_remove_avatar: false } },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'account/identities/list.php': { providers: [], identities: [], has_password: true },
      'account/mfa/status.php': { mfa: { enabled: false, available: false, credentials: [] } },
      'direct-messages/conversations.php': { conversations: [{ user: peer, unread_count: 0, last_message: { body: 'See you!', outgoing: false } }] },
      'direct-messages/block-status.php': { relationship: { blocked_by_me: false, messaging_available: true } },
      'direct-messages/history.php': { messages: messages.map((message) => ({ ...message, sender: { username: message.username }, outgoing: message.username === 'Jamie' })) },
      'direct-messages/read.php': {},
      'direct-messages/attachments/metadata.php': { attachments: [] },
      'direct-messages/message-mutations.php': { messages: [] },
      'policies/show.php': { document: { source: 'hosted', url: policies.rules.url, html: rulesHtml, version: 3, updated_at: '2026-11-08T09:00:00Z', change_summary: 'We added a section on self-promotion.' } },
      'policies/accept.php': { rules: { accepted_version: 3, accepted_at: '2026-11-08T10:30:00Z', acceptance_required: false } },
      'register.php': { user: { ...viewer, rules: accepted(3) }, csrf_token: 'fixture-2' },
      'guest/start.php': { user: { id: 9, username: 'Guest 0042', roles: [], guest: true, session_version: 1, rules: accepted(3) }, csrf_token: 'fixture-3' },
    };
    if (!(path in responses)) throw new Error(`Missing fixture: ${path}`);
    await route.fulfill({ json: responses[path] });
  });
}

async function accessible(page) {
  const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  expect(result.violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) }))).toEqual([]);
}

async function noOverflow(page) {
  await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  const width = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
  expect(width[0]).toBeLessThanOrEqual(width[1]);
}

const signedOut = null;
const pending = { ...viewer, rules: { accepted_version: 2, accepted_at: '2026-10-01T12:00:00Z', acceptance_required: true } };

for (const theme of ['dark', 'light']) {
  test(`${theme}: registration asks for the rules and links the privacy policy`, async ({ page }, testInfo) => {
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await page.emulateMedia({ colorScheme: theme, reducedMotion: 'reduce' });
    await page.setViewportSize({ width: 1440, height: 1000 });
    const requests = [];
    await fixture(page, { user: signedOut, requests });
    await page.goto('/?register=1');
    const checkbox = page.getByRole('checkbox', { name: /I agree to the community rules/ });
    await expect(checkbox).toBeVisible();
    await expect(checkbox).not.toBeChecked();
    await expect(page.getByRole('link', { name: /Read the community rules/ })).toHaveAttribute('href', policies.rules.url);
    await expect(page.getByRole('link', { name: /How your data is handled/ })).toHaveAttribute('href', policies.privacy.url);
    await expect(page.locator('[data-guest-rules]').first()).toBeVisible();
    await accessible(page);
    await page.screenshot({ path: testInfo.outputPath(`register-rules-${theme}.png`), fullPage: true });

    await page.locator('#register-username').fill('newperson');
    await page.locator('#register-password').fill('a long enough password');
    await page.getByRole('button', { name: 'Create account' }).click();
    expect(requests.some((entry) => entry.path === 'register.php')).toBe(false);
    expect(await checkbox.evaluate((element) => element.validity.valid)).toBe(false);

    // The reason is inline, tied to the box, and goes once it's ticked (no browser bubble over the button).
    await expect(page.locator('#register-rules-error')).toHaveText('Please agree to the community rules to create your account.');
    await expect(checkbox).toHaveAccessibleDescription('Please agree to the community rules to create your account.');
    await checkbox.check();
    await expect(page.locator('#register-rules-error')).toBeHidden();
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page.locator('#chat-shell')).toBeVisible();
    expect(requests.find((entry) => entry.path === 'register.php').body.accepted_rules_version).toBe(3);

    await page.setViewportSize({ width: 320, height: 740 });
    await noOverflow(page);
  });
}

test('a stale rules version at registration explains and asks again', async ({ page }) => {
  await fixture(page, {
    user: signedOut,
    overrides: { 'register.php': { status: 409, json: { error: { code: 'rules_version_outdated', message: 'stale' } } } },
  });
  await page.goto('/?register=1');
  await page.locator('#register-username').fill('newperson');
  await page.locator('#register-password').fill('a long enough password');
  await page.getByRole('checkbox', { name: /I agree/ }).check();
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page.locator('#auth-error')).toHaveText(/The community rules were just updated/);
  await expect(page.getByRole('checkbox', { name: /I agree/ })).not.toBeChecked();
  await expect(page.getByRole('checkbox', { name: /I agree/ })).toBeFocused();
});

test('guests agree by starting a visit', async ({ page }) => {
  const requests = [];
  await fixture(page, { user: signedOut, requests });
  await page.goto('/');
  await expect(page.locator('.guest-entry [data-guest-rules]')).toHaveText(/By looking around, you agree to the community rules/);
  await page.locator('.guest-entry [data-guest-start]').click();
  await expect(page.locator('#chat-shell')).toBeVisible();
  expect(requests.find((entry) => entry.path === 'guest/start.php').body.accepted_rules_version).toBe(3);
});

test('nothing configured changes nothing', async ({ page }) => {
  const requests = [];
  await fixture(page, { user: signedOut, sessionPolicies: { rules: null, privacy: null, legal_notice: null }, requests });
  await page.goto('/?register=1');
  await expect(page.locator('#register-agreement')).toBeHidden();
  await expect(page.locator('[data-guest-rules]').first()).toBeHidden();
  await expect(page.locator('.powered-by').first()).toHaveText('Powered by ChitChat!');
  await page.locator('#register-username').fill('newperson');
  await page.locator('#register-password').fill('a long enough password');
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
  expect('accepted_rules_version' in requests.find((entry) => entry.path === 'register.php').body).toBe(false);
  await expect(page.locator('.rules-banner')).toBeHidden();
});

for (const theme of ['dark', 'light']) {
  test(`${theme}: changed rules pause writing until the person agrees`, async ({ page }, testInfo) => {
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await page.emulateMedia({ colorScheme: theme, reducedMotion: 'reduce' });
    await page.setViewportSize({ width: 1440, height: 1000 });
    const requests = [];
    await fixture(page, { user: pending, requests });
    await page.goto('/');
    const dialog = page.getByRole('dialog', { name: 'The community rules have changed' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('heading', { name: 'The community rules have changed' })).toBeFocused();
    await expect(dialog).toContainText('What changed: “We added a section on self-promotion.”');
    await expect(dialog.getByRole('heading', { name: 'No self-promotion' })).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'I agree' })).toBeEnabled();
    await accessible(page);
    await page.screenshot({ path: testInfo.outputPath(`rules-dialog-${theme}.png`) });

    await dialog.getByRole('button', { name: 'Not now' }).click();
    await expect(dialog).toBeHidden();
    await expect(page.locator('#chat-shell > .rules-banner')).toBeVisible();
    await expect(page.locator('#composer-wrap .rules-notice')).toBeVisible();
    await expect(page.locator('#composer-form')).toBeHidden();
    await expect(page.locator('.message').first()).toBeVisible();
    await accessible(page);
    await page.screenshot({ path: testInfo.outputPath(`rules-paused-${theme}.png`) });
    await page.setViewportSize({ width: 320, height: 740 });
    await noOverflow(page);
    await page.screenshot({ path: testInfo.outputPath(`rules-paused-mobile-${theme}.png`) });

    await page.locator('#composer-wrap').getByRole('button', { name: 'Review rules' }).click();
    await dialog.getByRole('button', { name: 'I agree' }).click();
    await expect(dialog).toBeHidden();
    expect(requests.find((entry) => entry.path === 'policies/accept.php').body).toEqual({ version: 3 });
    await expect(page.locator('#chat-shell > .rules-banner')).toBeHidden();
    await expect(page.locator('#composer-form')).toBeVisible();
    await expect(page.locator('#rules-announcer')).toHaveText('Thanks. You can write again.');
  });
}

test('the dialog opens once per browser session, and a refused write opens it again', async ({ page }) => {
  await fixture(page, {
    user: { ...viewer, rules: accepted(3) },
    overrides: { 'rooms/send.php': { status: 403, json: { error: { code: 'rules_acceptance_required', message: 'Agree first.' } } } },
  });
  await page.goto('/');
  await expect(page.locator('.message').first()).toBeVisible();
  await expect(page.locator('.rules-dialog')).toHaveCount(0);
  await page.locator('#composer-input').fill('Hello');
  await page.locator('#composer-input').press('Enter');
  await expect(page.getByRole('dialog', { name: /community rules/ })).toBeVisible();
  await expect(page.locator('#chat-shell > .rules-banner')).toBeVisible();
});

test('external rules show a link instead of the text, and a stale version reloads', async ({ page }) => {
  let accepts = 0;
  await fixture(page, {
    user: pending,
    overrides: {
      'policies/show.php': { document: { source: 'external', url: 'https://rules.example.org/', version: 4, updated_at: '2026-11-08T09:00:00Z', change_summary: null } },
      'policies/accept.php': () => (++accepts === 1
        ? { status: 409, json: { error: { code: 'rules_version_outdated', message: 'stale' } } }
        : { rules: accepted(4) }),
    },
  });
  await page.goto('/');
  const dialog = page.getByRole('dialog', { name: /community rules/ });
  await expect(dialog.getByRole('link', { name: /Read the community rules/ })).toHaveAttribute('href', 'https://rules.example.org/');
  await expect(dialog.locator('.rules-body')).toBeHidden();
  await expect(dialog.locator('.rules-change-summary')).toBeHidden();
  await dialog.getByRole('button', { name: 'I agree' }).click();
  await expect(dialog).toContainText('The rules were updated again just now.');
  await dialog.getByRole('button', { name: 'I agree' }).click();
  await expect(dialog).toBeHidden();
});

test('rules removed meanwhile lift the pause instead of failing', async ({ page }) => {
  await fixture(page, {
    user: pending,
    overrides: { 'policies/show.php': { status: 404, json: { error: { code: 'policy_document_not_found', message: 'Not published.' } } } },
  });
  await page.goto('/');
  await expect(page.locator('#rules-announcer')).toHaveText('The community rules were removed. You can write again.');
  await expect(page.locator('.rules-dialog')).not.toHaveAttribute('open', '');
  await expect(page.locator('#chat-shell > .rules-banner')).toBeHidden();
  await expect(page.locator('#composer-form')).toBeVisible();
});

test('direct messages pause writing too', async ({ page }) => {
  await fixture(page, { user: pending });
  await page.addInitScript(() => sessionStorage.setItem('chitchat-rules-dialog-shown', '1'));
  await page.goto('/messages.php?with=2');
  await expect(page.locator('.dm-message').first()).toBeVisible();
  await expect(page.locator('#messages-shell > .rules-banner')).toBeVisible();
  await expect(page.locator('.conversation-main .rules-notice')).toBeVisible();
  await expect(page.locator('#dm-composer')).toBeHidden();
  await accessible(page);
});

test('account shows when you agreed, or asks you to', async ({ page }) => {
  await fixture(page, { user: { ...viewer, rules: accepted(3) } });
  await page.goto('/account.php');
  const card = page.locator('#rules-card');
  await expect(card).toBeVisible();
  await expect(card).toContainText('You agreed to the community rules on');
  await expect(card.getByRole('link', { name: 'Privacy policy' })).toHaveAttribute('href', policies.privacy.url);
  await expect(card.getByRole('button', { name: 'Review and agree' })).toBeHidden();
  await accessible(page);

  await page.unroute('**/api/v1/**');
  await fixture(page, { user: pending });
  await page.addInitScript(() => sessionStorage.setItem('chitchat-rules-dialog-shown', '1'));
  await page.goto('/account.php');
  await expect(card).toContainText('The community rules have changed since you agreed.');
  await card.getByRole('button', { name: 'Review and agree' }).click();
  await page.getByRole('dialog', { name: /community rules/ }).getByRole('button', { name: 'I agree' }).click();
  await expect(card).toContainText('You agreed to the community rules on');
});

test('administrators publish rules and choose whether members agree again', async ({ page }, testInfo) => {
  const requests = [];
  const admin = { ...viewer, roles: ['super_admin'], rules: accepted(3) };
  await fixture(page, {
    user: admin,
    requests,
    overrides: {
      'admin/settings/get.php': { settings: { registration_enabled: true, guest_access_enabled: false, mfa_required_for_admin_roles: false, audit_retention_days: 365, deleted_attachment_retention_days: 30, deleted_room_grace_days: 30, direct_message_retention_days: 0, login_attempt_retention_days: 30, orphan_attachment_grace_hours: 24, realtime_event_retention_hours: 24, room_message_retention_days: 0, updated_at: '2026-11-01T10:00:00Z' } },
      'admin/settings/lockdown/get.php': { lockdown: { enabled: false, custom_message: null, since: null } },
      'admin/settings/application-name/get.php': { application_name: { default: 'ChitChat', override: null } },
      // Operational settings also loads the sign-in decoration card (#242).
      'admin/settings/sign-in-decoration/get.php': { sign_in_decoration: { value: 'off', options: ['off', 'winter', 'spring', 'celebration'] } },
      // #211's card loads beside the policies; this test doesn't look at it.
      'admin/settings/application-icon/get.php': { application_icon: null },
      // The authenticator key card (#204); a server without a key yet.
      'admin/settings/totp/get.php': { totp: { mode: 'unavailable', available: false, database_key_enabled: false, database_key_switch_available: true, database_key_references: 0, warning: '' } },
      'admin/settings/registration-protection/get.php': { registration_protection: { overrides: { rate_limit_max_attempts: null, rate_limit_window_seconds: null, min_fill_seconds: null, proof_of_work_bits: null }, defaults: { rate_limit_max_attempts: 5, rate_limit_window_seconds: 3600, min_fill_seconds: 3, proof_of_work_bits: 16 }, effective: { rate_limit_max_attempts: 5, rate_limit_window_seconds: 3600, min_fill_seconds: 3, proof_of_work_bits: 16 } } },
      'admin/policies.php': { policies: {
        rules: { source: 'hosted', markdown: '## Be kind\n\nTreat people well.', url: null, version: 3, updated_at: '2026-11-08T09:00:00Z' },
        privacy: { source: 'none', markdown: '', url: null, version: 0, updated_at: null },
        legal_notice: { url: null },
      } },
      'admin/policies/preview.php': { html: '<h2>Be kind</h2><p>Treat people well, always.</p>' },
      'admin/policies/update.php': (request) => {
        const body = request.postDataJSON();
        return { document: { source: body.source, markdown: body.markdown ?? '', url: body.url, version: body.material ? 4 : 3, updated_at: '2026-11-08T11:00:00Z' } };
      },
    },
  });
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto('/admin-settings.php');
  const rules = page.locator('form[data-policy="rules"]');
  await expect(rules.getByRole('radio', { name: 'Write it here' })).toBeChecked();
  await expect(rules.locator('.policy-meta')).toContainText('Shown at /rules.php');
  await expect(rules.locator('.policy-change')).toBeHidden();

  await rules.getByRole('textbox', { name: /Text/ }).fill('## Be kind\n\nTreat people well, always.');
  await expect(rules.locator('.policy-change')).toBeVisible();
  await expect(rules.getByRole('radio', { name: /Small fix/ })).toBeChecked();
  await rules.getByRole('radio', { name: 'Members must agree again' }).check();
  await expect(rules.getByRole('textbox', { name: /What changed/ })).toBeVisible();
  await rules.getByRole('textbox', { name: /What changed/ }).fill('Kindness, always.');
  await rules.getByRole('button', { name: 'Preview' }).click();
  await expect(rules.locator('.policy-preview')).toContainText('Treat people well, always.');
  await accessible(page);
  await page.locator('.policies-card').screenshot({ path: testInfo.outputPath('admin-policies.png') });
  await rules.getByRole('button', { name: 'Save community rules' }).click();
  // The request arrives after the click resolves; wait for it rather than racing it.
  await expect.poll(() => requests.some((entry) => entry.path === 'admin/policies/update.php')).toBe(true);
  const saved = requests.find((entry) => entry.path === 'admin/policies/update.php').body;
  expect(saved).toMatchObject({ document: 'rules', source: 'hosted', material: true, change_summary: 'Kindness, always.' });
  await expect(rules.locator('.policy-change')).toBeHidden();
  await expect(rules.locator('input[name="rules-material"][value="0"]')).toBeChecked();
  await expect(rules.locator('.policy-meta')).toContainText('version 4');

  const privacy = page.locator('form[data-policy="privacy"]');
  await privacy.getByRole('radio', { name: 'Link to another site' }).check();
  await expect(privacy.locator('.policy-first')).toBeVisible();
  await expect(privacy.locator('.policy-change')).toBeHidden();
  await privacy.getByRole('textbox', { name: 'Address of the page' }).fill('http://not-secure.example');
  await privacy.getByRole('button', { name: 'Save privacy policy' }).click();
  await expect(privacy.locator('.error-text')).toHaveText(/starting with https:\/\//);

  // The limit is in UTF-8 bytes: 30,000 "ä" are 60,000 bytes (near), 33,000 are too many.
  await rules.getByRole('textbox', { name: /Text/ }).fill('ä'.repeat(30000));
  await expect(rules.locator('.policy-size')).toHaveText('59 KB of 64 KB used.');
  await rules.getByRole('textbox', { name: /Text/ }).fill('ä'.repeat(33000));
  await rules.getByRole('button', { name: 'Save community rules' }).click();
  await expect(rules.locator('.error-text[role="alert"]')).toHaveText(/The text is too long: 65 KB of at most 64 KB/);
  await rules.getByRole('textbox', { name: /Text/ }).fill('## Be kind');

  await rules.getByRole('radio', { name: 'None' }).check();
  await expect(rules.locator('.policy-none-warning')).toBeVisible();
  await page.setViewportSize({ width: 320, height: 740 });
  await noOverflow(page);
});

test('an unpublished document page says so', async ({ page }) => {
  const response = await page.goto('/rules.php');
  expect(response.status()).toBe(404);
  await expect(page.getByRole('heading', { level: 1, name: 'Community rules' })).toBeVisible();
  await expect(page.locator('main')).toContainText('hasn\'t published this page');
  await accessible(page);
});
