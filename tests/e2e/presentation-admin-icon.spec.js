import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// #211: Operational settings → Installation icon. Previews show what the
// server renders (circle, rounded square with the safe zone on request, home
// screen tile, favicon at actual size). Choosing a file asks for a dry_run
// preview and saves nothing; "Use this icon" uploads again for real.
// Presentation only: the real page and module against intercepted responses.
const admin = { id: 1, username: 'Root', roles: ['super_admin'], session_version: 1 };
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const DATA_PNG = `data:image/png;base64,${PNG.toString('base64')}`;
const policy = { enabled: true, max_bytes: 4 * 1024 * 1024, minimum_side: 512, maximum_side: 4000, allowed_mime_types: ['image/png', 'image/webp'] };

function rendition(url, size) {
  return { url, width: size, height: size, mime_type: 'image/png', size_bytes: 1000, sha256: 'a'.repeat(64) };
}

const defaultIcon = {
  state: 'default', revision: null, background: '#14111C', source_width: null, source_height: null, maskable_scale: null,
  updated_at: null, updated_by: null, upload_policy: policy,
  renditions: {
    favicon: rendition('/assets/app-icons/favicon-32.png', 32), apple_touch: rendition('/assets/app-icons/apple-touch-icon.png', 180),
    icon_192: rendition('/assets/app-icons/icon-192.png', 192), icon_512: rendition('/assets/app-icons/icon-512.png', 512),
    maskable: rendition('/assets/app-icons/icon-maskable-512.png', 512), ico: { ...rendition('/assets/app-icons/favicon.ico', 32), mime_type: 'image/x-icon' },
  },
};
const R = 'b'.repeat(64);
const variant = (name) => `/installation-icon.php?revision=${R}&variant=${name}`;
const customIcon = {
  ...defaultIcon, state: 'custom', revision: R, source_width: 1024, source_height: 1024, maskable_scale: 0.5605,
  updated_at: '2026-11-09T12:00:00Z', updated_by: { id: 1, username: 'Root' },
  renditions: Object.fromEntries(Object.keys(defaultIcon.renditions).map((name) => [name, rendition(variant(name), 32)])),
};

async function fixture(page, { icon = defaultIcon, updates = [], onUpdate = null } = {}) {
  await page.route('**/installation-icon.php*', (route) => route.fulfill({ contentType: 'image/png', body: PNG }));
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace('/api/v1/', '');
    if (path === 'admin/settings/application-icon/update.php') {
      const body = request.postDataBuffer()?.toString('latin1') ?? '';
      const field = (name) => new RegExp(`name="${name}"\\r\\n\\r\\n([^\\r]*)`).exec(body)?.[1] ?? null;
      const entry = { dryRun: field('dry_run') === '1', background: field('background'), hasFile: body.includes('name="icon"') };
      updates.push(entry);
      if (onUpdate) return onUpdate(route, entry);
      if (entry.dryRun) return route.fulfill({ json: { preview: { favicon: DATA_PNG, apple_touch: DATA_PNG, maskable: DATA_PNG }, maskable_scale: 0.6, background: entry.background } });
      return route.fulfill({ json: { application_icon: { ...customIcon, background: entry.background ?? '#14111C' } } });
    }
    if (path === 'admin/settings/application-icon/reset.php') return route.fulfill({ json: { application_icon: defaultIcon } });
    const responses = {
      'session.php': { user: admin, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'admin/settings/get.php': { settings: { registration_enabled: true, guest_access_enabled: false, mfa_required_for_admin_roles: false, audit_retention_days: 365, deleted_attachment_retention_days: 30, deleted_room_grace_days: 30, direct_message_retention_days: 0, login_attempt_retention_days: 30, orphan_attachment_grace_hours: 24, realtime_event_retention_hours: 24, room_message_retention_days: 0, updated_at: '2026-11-01T10:00:00Z' } },
      'admin/settings/lockdown/get.php': { lockdown: { enabled: false, custom_message: null, since: null } },
      'admin/settings/application-name/get.php': { application_name: { default: 'ChitChat', override: null } },
      'admin/settings/registration-protection/get.php': { registration_protection: { overrides: { rate_limit_max_attempts: null, rate_limit_window_seconds: null, min_fill_seconds: null, proof_of_work_bits: null }, defaults: { rate_limit_max_attempts: 5, rate_limit_window_seconds: 3600, min_fill_seconds: 3, proof_of_work_bits: 16 }, effective: { rate_limit_max_attempts: 5, rate_limit_window_seconds: 3600, min_fill_seconds: 3, proof_of_work_bits: 16 } } },
      'admin/policies.php': { policies: { rules: { source: 'none', markdown: '', url: null, version: 0, updated_at: null }, privacy: { source: 'none', markdown: '', url: null, version: 0, updated_at: null }, legal_notice: { url: null } } },
      'admin/settings/application-icon/get.php': { application_icon: icon },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return updates;
}

const card = (page) => page.locator('#icon-settings');
const previewImages = (page, name) => card(page).locator(`img[data-icon-variant="${name}"]`);

async function open(page) {
  await page.goto('/admin-settings.php');
  await expect(card(page).getByRole('heading', { name: 'Installation icon' })).toBeVisible();
  await expect(card(page).locator('#icon-body')).toBeVisible();
}

test('the default state shows the ChitChat icon in every preview, with no Reset', async ({ page }) => {
  await fixture(page);
  await open(page);
  await expect(card(page).locator('#icon-preview-label')).toHaveText('Current icon: the ChitChat icon');
  await expect(previewImages(page, 'maskable').first()).toHaveAttribute('src', '/assets/app-icons/icon-maskable-512.png');
  await expect(previewImages(page, 'apple_touch')).toHaveAttribute('src', '/assets/app-icons/apple-touch-icon.png');
  await expect(previewImages(page, 'favicon').first()).toHaveAttribute('src', '/assets/app-icons/favicon-32.png');
  await expect(card(page).getByRole('button', { name: 'Use the ChitChat icon' })).toBeHidden();
  await expect(card(page).locator('#icon-file-help')).toHaveText('A square PNG or WebP image, at least 512 × 512 pixels and at most 4000 × 4000, up to 4 MB.');
  await expect(card(page).getByRole('radio', { name: 'Night' })).toBeChecked();
  // The favicon preview is drawn at its real size.
  const box = await previewImages(page, 'favicon').first().boundingBox();
  expect(Math.round(box.width)).toBe(16);
});

test('choosing a file previews without saving; "Use this icon" saves it', async ({ page }) => {
  const updates = await fixture(page);
  await open(page);
  await card(page).getByLabel('Upload an image').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: PNG });
  await expect(card(page).locator('#icon-preview-label')).toHaveText('Preview: not saved yet');
  await expect(card(page).locator('#icon-previews')).toHaveAttribute('data-mode', 'preview');
  await expect(previewImages(page, 'maskable').first()).toHaveAttribute('src', /^data:image\/png/);
  await expect(card(page).locator('#icon-status')).toContainText('Nothing is saved until you choose “Use this icon”.');
  expect(updates).toEqual([{ dryRun: true, background: '#14111C', hasFile: true }]);

  // A different background asks for a fresh preview, still without saving.
  await card(page).getByRole('radio', { name: 'Paper' }).check();
  await expect.poll(() => updates.length).toBe(2);
  expect(updates[1]).toEqual({ dryRun: true, background: '#FBF7F1', hasFile: true });

  await card(page).getByRole('button', { name: 'Use this icon' }).click();
  await expect(card(page).locator('#icon-status')).toHaveText('Icon saved. Pages and new installs use it the next time they load.');
  expect(updates[2]).toEqual({ dryRun: false, background: '#FBF7F1', hasFile: true });
  await expect(card(page).locator('#icon-preview-label')).toHaveText('Current icon');
  await expect(card(page).getByRole('button', { name: 'Use the ChitChat icon' })).toBeVisible();
  await expect(card(page).locator('#icon-meta')).toContainText('From a 1024 × 1024 image, uploaded');
});

test('"Choose another" drops the preview and saves nothing', async ({ page }) => {
  const updates = await fixture(page);
  await open(page);
  await card(page).getByLabel('Upload an image').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: PNG });
  await expect(card(page).getByRole('button', { name: 'Use this icon' })).toBeVisible();
  await card(page).getByRole('button', { name: 'Choose another' }).click();
  await expect(card(page).locator('#icon-previews')).toHaveAttribute('data-mode', 'current');
  await expect(card(page).getByRole('button', { name: 'Use this icon' })).toBeHidden();
  await expect(card(page).getByLabel('Upload an image')).toBeFocused();
  expect(updates.every((entry) => entry.dryRun)).toBe(true);
});

test('a custom icon previews a background change live, then saves only the background', async ({ page }) => {
  const updates = await fixture(page, { icon: customIcon });
  await open(page);
  await expect(previewImages(page, 'maskable').first()).toHaveAttribute('src', variant('maskable'));
  await card(page).getByLabel('Custom background colour, as #RRGGBB').fill('#2a6');
  await expect(card(page).getByRole('radio', { name: 'Custom' })).toBeChecked();
  const mask = card(page).locator('.icon-mask-circle');
  await expect(mask).toHaveClass(/is-live/);
  await expect(mask.locator('img')).toHaveAttribute('src', variant('icon_512'));
  expect(await mask.evaluate((node) => getComputedStyle(node).backgroundColor)).toBe('rgb(34, 170, 102)');
  const sizes = await mask.evaluate((node) => [node.getBoundingClientRect().width, node.querySelector('img').getBoundingClientRect().width]);
  expect(Math.abs(sizes[1] / sizes[0] - 0.5605)).toBeLessThan(0.02);
  expect(updates).toEqual([]);

  await card(page).getByRole('button', { name: 'Save background' }).click();
  await expect(card(page).locator('#icon-status')).toHaveText('Background saved.');
  expect(updates).toEqual([{ dryRun: false, background: '#22AA66', hasFile: false }]);
});

test('the safe zone ring shows on request', async ({ page }) => {
  await fixture(page, { icon: customIcon });
  await open(page);
  const ring = card(page).locator('.icon-mask-circle .icon-safe-ring');
  await expect(ring).toBeHidden();
  await card(page).getByLabel(/Show the safe zone/).check();
  await expect(ring).toBeVisible();
  const ratio = await ring.evaluate((node) => node.getBoundingClientRect().width / node.parentElement.getBoundingClientRect().width);
  expect(Math.abs(ratio - 0.8)).toBeLessThan(0.02);
});

test('reset returns to the ChitChat icon after confirmation', async ({ page }) => {
  await fixture(page, { icon: customIcon });
  await open(page);
  page.once('dialog', (dialog) => dialog.accept());
  await card(page).getByRole('button', { name: 'Use the ChitChat icon' }).click();
  await expect(card(page).locator('#icon-status')).toHaveText('Back to the ChitChat icon.');
  await expect(card(page).locator('#icon-preview-label')).toHaveText('Current icon: the ChitChat icon');
  await expect(card(page).getByRole('button', { name: 'Use the ChitChat icon' })).toBeHidden();
});

test('errors are plain: too large, wrong size, and a concurrent change', async ({ page }) => {
  let reply = 'large';
  await fixture(page, {
    onUpdate: (route, entry) => {
      if (reply === 'large') return route.fulfill({ status: 413, json: { error: { code: 'attachment_too_large', message: 'Too large.', max_bytes: policy.max_bytes } } });
      if (reply === 'invalid') return route.fulfill({ status: 400, json: { error: { code: 'validation_error', message: 'The icon must be a square PNG or WebP image of at least 512 × 512 pixels.' } } });
      if (entry.dryRun) return route.fulfill({ json: { preview: { favicon: DATA_PNG, apple_touch: DATA_PNG, maskable: DATA_PNG }, maskable_scale: 0.6, background: '#14111C' } });
      return route.fulfill({ status: 409, json: { error: { code: 'application_icon_changed', message: 'Changed.' } } });
    },
  });
  await open(page);
  const file = card(page).getByLabel('Upload an image');
  await file.setInputFiles({ name: 'big.png', mimeType: 'image/png', buffer: PNG });
  await expect(card(page).locator('#icon-error')).toHaveText('That image is too large. The limit is 4 MB.');
  reply = 'invalid';
  await file.setInputFiles({ name: 'small.png', mimeType: 'image/png', buffer: PNG });
  await expect(card(page).locator('#icon-error')).toHaveText('The icon must be a square PNG or WebP image of at least 512 × 512 pixels.');
  reply = 'conflict';
  await file.setInputFiles({ name: 'ok.png', mimeType: 'image/png', buffer: PNG });
  await card(page).getByRole('button', { name: 'Use this icon' }).click();
  await expect(card(page).locator('#icon-error')).toHaveText(/Someone else changed the icon just now/);
});

test('uploads unavailable on this server: says so and hides the file field', async ({ page }) => {
  await fixture(page, { icon: { ...defaultIcon, upload_policy: { ...policy, enabled: false, max_bytes: 0 } } });
  await open(page);
  await expect(card(page).getByLabel('Upload an image')).toBeHidden();
  await expect(card(page).locator('#icon-file-help')).toHaveText(/Uploads aren’t available on this server/);
});

for (const theme of ['light', 'dark']) {
  test(`${theme}: the icon card passes axe and reflows at 360px`, async ({ page }) => {
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await fixture(page, { icon: customIcon });
    await page.setViewportSize({ width: 360, height: 900 });
    await open(page);
    await card(page).getByLabel(/Show the safe zone/).check();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
    const result = await new AxeBuilder({ page }).include('#icon-settings').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations).toEqual([]);
  });
}
