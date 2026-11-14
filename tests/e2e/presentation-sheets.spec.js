import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// S7 (#173): below 768px every modal is a bottom sheet (docked to the bottom
// edge, full width, its own scroll); at desktop widths it stays a centred
// dialog. One of each class: a decision dialog (Leave room), a form dialog
// (Create room) and the help dialog (/help). Focus moves in, Escape closes,
// focus returns, and the decision dialog is described by its sentence.
// Presentation only: the real page against intercepted APIs.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const admin = { id: 1, username: 'Jamie', roles: ['admin'], session_version: 1, username_change: null };
const rooms = [{
  id: 1, name: 'Garden', key: 'garden', visibility: 'public', member_role: 'member', minimum_age: 0,
  inactivity_timeout_seconds: 0, info_line: '', unread_count: 0, guest_access: 'none',
}];

async function fixture(page) {
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1/', '');
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    const responses = {
      'session.php': { user: admin, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'rooms/list.php': { rooms },
      'rooms/messages.php': { messages: [] },
      'rooms/message-mutations.php': { messages: [] },
      'attachments/metadata.php': { attachments: [] },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [] },
      'rooms/mentionable-users.php': { users: [] },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: 0 },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
}

async function openRoom(page, width) {
  await page.setViewportSize({ width, height: 760 });
  await page.goto('/');
  if (width < 768) await page.locator('#room-list .room-button').first().click();
  await expect(page.locator('#room-title')).toContainText('Garden');
}

const DIALOGS = [
  {
    name: 'decision (Leave room)',
    open: async (page) => {
      await page.locator('#members-toggle').click();
      await page.getByRole('button', { name: 'Leave room…' }).click();
      return page.getByRole('dialog', { name: 'Leave Garden?' });
    },
    opener: (page) => page.getByRole('button', { name: 'Leave room…' }),
  },
  {
    name: 'form (Create room)',
    open: async (page) => {
      // On phones the Rooms list holds the button; go back to it first.
      if ((page.viewportSize()?.width ?? 1280) < 768) await page.getByRole('button', { name: /^Back/ }).click();
      await page.getByRole('button', { name: 'Create room' }).click();
      return page.getByRole('dialog', { name: 'Create room' });
    },
    opener: (page) => page.getByRole('button', { name: 'Create room' }),
  },
  {
    name: 'help (/help)',
    open: async (page) => {
      await page.locator('#composer-input').fill('/help');
      await page.locator('#composer-input').press('Enter');
      return page.getByRole('dialog');
    },
    opener: null,
  },
];

for (const dialogCase of DIALOGS) {
  test(`${dialogCase.name}: a bottom sheet at 360, a centred dialog at 1280; focus in, Escape out`, async ({ page }) => {
    await fixture(page);
    for (const width of [360, 1280]) {
      await openRoom(page, width);
      const dialog = await dialogCase.open(page);
      await expect(dialog).toBeVisible();
      await page.waitForTimeout(300);
      expect(await dialog.evaluate((node) => node.contains(document.activeElement)), `${width}: focus moved in`).toBe(true);
      const box = await dialog.boundingBox();
      const viewport = page.viewportSize();
      if (width < 768) {
        expect(Math.abs(box.y + box.height - viewport.height), `${width}: docked to the bottom`).toBeLessThanOrEqual(1);
        expect(Math.round(box.width), `${width}: full width`).toBe(viewport.width);
      } else {
        expect(box.y + box.height, `${width}: not docked`).toBeLessThan(viewport.height - 8);
        expect(box.width).toBeLessThan(viewport.width - 100);
      }
      const result = await new AxeBuilder({ page }).include('dialog[open]').withTags(WCAG).analyze();
      expect(result.violations, `${width}: ${result.violations.map((violation) => violation.id).join(', ')}`).toEqual([]);
      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();
      if (dialogCase.opener) await expect(dialogCase.opener(page)).toBeFocused();
    }
  });
}

test('the help sheet opens at the top on a phone, with focus on its heading', async ({ page }) => {
  await fixture(page);
  await openRoom(page, 360);
  const dialog = await DIALOGS[2].open(page);
  await expect(dialog.getByRole('heading', { name: 'Commands and formatting' })).toBeFocused();
  await expect.poll(() => dialog.evaluate((node) => node.scrollTop)).toBe(0);
});

test('the decision dialog is described by the sentence that names its effect', async ({ page }) => {
  await fixture(page);
  await openRoom(page, 1280);
  const dialog = await DIALOGS[0].open(page);
  await expect(dialog).toHaveAccessibleDescription('You stop getting its messages. You can join again from the room list any time.');
});
