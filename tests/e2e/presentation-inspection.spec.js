import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// S6c (#172, spec comment 6842902529): DM inspection and message revision
// review in the Dusk frame. Both keep their step-up and their audit; their
// notices say plainly what opening content means. Until now no spec covered
// either page's UI. Presentation only: the real pages against intercepted APIs.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const root = { id: 1, username: 'Root', roles: ['super_admin'], session_version: 1 };
const rowan = { id: 2, username: 'Rowan' };
const sam = { id: 3, username: 'Sam' };
const privacy = {
  direct_messages: { admin_inspection_enabled: true, admin_inspection_role: 'super_admin' },
  message_revisions: { admin_review_enabled: true, admin_review_role: 'super_admin' },
};
const dm = (id, from, to, body) => ({ id, body, sender: from, recipient: to, created_at: `2026-10-0${id}T10:00:00Z`, read_at: id === 1 ? '2026-10-01T11:00:00Z' : null });
const review = {
  kind: 'room',
  message: {
    id: 77, room: { id: 4, key: 'garden', name: 'Garden' }, author: rowan, message_type: 'text',
    created_at: '2026-10-01T10:00:00Z', edited_at: '2026-10-01T10:05:00Z', last_editor: rowan, deleted_at: null, deleted_by: null,
  },
  revisions: [{ id: 501, action: 'edit', actor: rowan, message_type: 'text', created_at: '2026-10-01T10:05:00Z', body_before: 'Seeds on Friday', body_after: 'Seeds on Saturday' }],
};

async function fixture(page, { sessionPrivacy = privacy } = {}) {
  const requests = [];
  await page.clock.setFixedTime(new Date('2026-10-04T12:00:00Z'));
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname.replace('/api/v1/', '');
    if (request.method() === 'POST') requests.push({ path, body: request.postDataJSON?.() ?? null });
    const responses = {
      'session.php': { user: root, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [], privacy: sessionPrivacy },
      'admin/direct-messages/users.php': { users: url.searchParams.get('search')?.toLowerCase().startsWith('ro') ? [rowan] : [sam] },
      'admin/direct-messages/inspect.php': { user_a: rowan, user_b: sam, messages: [dm(1, rowan, sam, 'Are you coming on Friday?'), dm(2, sam, rowan, 'Yes, with the seeds.')] },
      'admin/message-revisions/review.php': review,
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return requests;
}

async function axe(page, label) {
  await page.mouse.move(0, 0);
  await page.waitForTimeout(250);
  const result = await new AxeBuilder({ page }).withTags(WCAG).analyze();
  expect(result.violations, `${label}: ${result.violations.map((violation) => `${violation.id} ${violation.nodes.map((node) => node.target).join(' ')}`).join('; ')}`).toEqual([]);
}

const posts = (requests, path) => requests.filter((entry) => entry.path === path);

// ---- DM inspection ----

test('DM inspection: the notice says what opening means; pick two people, give a reason, and the page is audited', async ({ page }) => {
  const requests = await fixture(page);
  await page.goto('/admin-messages.php');
  const notice = page.getByRole('region', { name: 'This opens a private conversation between two people' });
  await expect(notice).toContainText('Every page you open goes in the audit log');

  await page.locator('#inspection-user-a-search').fill('Ro');
  await page.locator('#inspection-user-a-search-button').click();
  await page.locator('#inspection-user-a-results').getByRole('button', { name: /Rowan/ }).click();
  await expect(page.locator('#inspection-user-a-selected')).toContainText('Rowan');
  await page.locator('#inspection-user-b-search').fill('Sa');
  await page.locator('#inspection-user-b-search-button').click();
  await page.locator('#inspection-user-b-results').getByRole('button', { name: /Sam/ }).click();

  // A reason is required before anything is opened (the form itself refuses a short one).
  await page.locator('#inspection-reason').fill('x');
  await page.locator('#inspection-submit').click();
  expect(await page.locator('#inspection-reason').evaluate((field) => field.validity.tooShort)).toBe(true);
  expect(posts(requests, 'admin/direct-messages/inspect.php')).toEqual([]);

  await page.locator('#inspection-reason').fill('Harassment report #42');
  await page.locator('#inspection-submit').click();
  await expect(page.locator('#inspection-conversation-title')).toHaveText('Rowan ↔ Sam');
  await expect(page.locator('#inspection-conversation-meta')).toHaveText('This page was audited with reason: Harassment report #42');
  await expect(page.locator('#inspection-message-list .dm-message')).toHaveCount(2);
  expect(posts(requests, 'admin/direct-messages/inspect.php')[0].body).toMatchObject({ user_a_id: 2, user_b_id: 3, reason: 'Harassment report #42', before_id: null, limit: 100 });
  await axe(page, 'inspection results');
});

test('DM inspection: two characters to search, and two people chosen before anything opens', async ({ page }) => {
  const requests = await fixture(page);
  await page.goto('/admin-messages.php');
  await page.locator('#inspection-user-a-search').fill('R');
  await page.locator('#inspection-user-a-search-button').click();
  await expect(page.locator('#inspection-error')).toHaveText('Enter at least two username characters.');
  // Searched, but nobody chosen yet.
  await page.locator('#inspection-user-a-search').fill('Ro');
  await page.locator('#inspection-user-b-search').fill('Sa');
  await page.locator('#inspection-reason').fill('A real reason');
  await page.locator('#inspection-submit').click();
  await expect(page.locator('#inspection-error')).toHaveText('Select two users before inspecting a conversation.');
  expect(posts(requests, 'admin/direct-messages/inspect.php')).toEqual([]);
});

test('DM inspection: switched off, the page says so instead of opening', async ({ page }) => {
  await fixture(page, { sessionPrivacy: { ...privacy, direct_messages: { admin_inspection_enabled: false, admin_inspection_role: 'super_admin' } } });
  await page.goto('/admin-messages.php');
  await expect(page.locator('#inspection-loading')).toHaveText('Administrative direct-message inspection is disabled.');
  await expect(page.locator('#inspection-shell')).toBeHidden();
});

// ---- Revision review ----

test('Revision review: the notice says what it shows; the review lists each version before and after', async ({ page }) => {
  const requests = await fixture(page);
  await page.goto('/admin-message-revisions.php');
  const notice = page.getByRole('region', { name: 'This shows earlier versions of a message, which its participants can’t see' });
  await expect(notice).toContainText('so say in your privacy policy that reviews can happen');

  await page.locator('#revision-review-message-id').fill('77');
  await page.locator('#revision-review-reason').fill('too short');
  await page.locator('#revision-review-submit').click();
  expect(await page.locator('#revision-review-reason').evaluate((field) => field.validity.tooShort)).toBe(true);
  expect(posts(requests, 'admin/message-revisions/review.php')).toEqual([]);

  await page.locator('#revision-review-reason').fill('Complaint about an edited offer');
  await page.locator('#revision-review-submit').click();
  await expect(page.locator('#revision-review-summary')).toContainText('1 retained revision · audited reason: Complaint about an edited offer');
  const card = page.locator('#revision-review-list > *').first();
  await expect(card).toContainText('Revision 1 · Edit');
  await expect(card).toContainText('Seeds on Friday');
  await expect(card).toContainText('Seeds on Saturday');
  expect(posts(requests, 'admin/message-revisions/review.php')[0].body).toEqual({ kind: 'room', message_id: 77, reason: 'Complaint about an edited offer' });
  await axe(page, 'revision results');
});

// ---- The frame, axe and reflow ----

for (const [scheme, theme] of [['lantern', 'light'], ['lantern', 'dark'], ['dusk', 'dark']]) {
  test(`${scheme} ${theme}: DM inspection and revision review pass axe at 1280 and 360, and reflow at 320`, async ({ page }) => {
    await page.addInitScript(([chosenScheme, chosenTheme]) => {
      localStorage.setItem('chitchat-theme', chosenTheme);
      localStorage.setItem('chitchat-scheme', chosenScheme);
    }, [scheme, theme]);
    await fixture(page);
    for (const path of ['/admin-messages.php', '/admin-message-revisions.php']) {
      for (const width of [1280, 360]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(path);
        await expect(page.locator('main')).toBeVisible();
        await axe(page, `${path} ${width}`);
      }
      await page.setViewportSize({ width: 320, height: 700 });
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `${path} at 320`).toBe(true);
    }
  });
}

test('the notices use the steward accent, not the error colour', async ({ page }) => {
  await fixture(page);
  await page.goto('/admin-messages.php');
  const notice = page.locator('.steward-notice');
  await expect.poll(() => notice.evaluate((node) => {
    const probe = document.createElement('span');
    probe.style.color = 'var(--steward)';
    document.body.append(probe);
    const steward = getComputedStyle(probe).color;
    probe.remove();
    return getComputedStyle(node).borderLeftColor === steward;
  })).toBe(true);
});
