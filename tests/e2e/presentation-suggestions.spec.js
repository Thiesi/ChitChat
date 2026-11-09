import { expect, test } from '@playwright/test';

// The "@" and "/" suggestion lists: the option Enter would pick must be
// visible without a mouse. It was styled with the list's own background, so
// keyboard users couldn't see it. Every scheme and both themes, plus a
// non-colour cue (an accent bar of at least 3:1 against the list).
// Presentation only: real templates against intercepted API responses.
const viewer = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const rooms = [{
  id: 1, name: 'The living room', key: 'room-0', visibility: 'public', member_role: 'member', unread_count: 0,
  minimum_age: 0, inactivity_timeout_seconds: 0, info_line: 'Everyday talk.', guest_access: 'none',
}];
const people = ['Priya', 'Rowan', 'Sam', 'Tariq', 'Uma', 'Vera', 'Wren'].map((username, index) => ({ id: index + 2, username }));
const SCHEMES = ['lantern', 'lounge', 'dusk', 'ember', 'rose', 'midnight', 'contrast'];

async function fixture(page) {
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1/', '');
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    const responses = {
      'session.php': { user: viewer, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'rooms/list.php': { rooms },
      'rooms/messages.php': { messages: [] },
      'rooms/message-mutations.php': { messages: [] },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [] },
      'rooms/mentionable-users.php': { users: people },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: 0 },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
}

async function openComposer(page) {
  await page.goto('/');
  const input = page.locator('#composer-input');
  await expect(input).toBeEnabled();
  return input;
}

const list = (page) => page.locator('#composer-input-mentions');
const options = (page) => list(page).getByRole('option');

/** Relative luminance contrast between two computed rgb()/color() strings. */
function contrastIn(page, a, b) {
  return page.evaluate(([first, second]) => {
    const toRgb = (value) => {
      const canvas = document.createElement('canvas');
      canvas.width = canvas.height = 1;
      const context = canvas.getContext('2d');
      context.fillStyle = value;
      context.fillRect(0, 0, 1, 1);
      return [...context.getImageData(0, 0, 1, 1).data].slice(0, 3);
    };
    const luminance = (rgb) => {
      const [r, g, b] = rgb.map((channel) => {
        const c = channel / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
      });
      return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    const [l1, l2] = [luminance(toRgb(first)), luminance(toRgb(second))].sort((x, y) => y - x);
    return (l1 + 0.05) / (l2 + 0.05);
  }, [a, b]);
}

const style = (locator) => locator.evaluate((node) => {
  const computed = getComputedStyle(node);
  return { background: computed.backgroundColor, bar: computed.borderInlineStartColor, barWidth: computed.borderInlineStartWidth };
});

test('"@": ArrowDown makes the second option active and visibly distinct, and Enter inserts it', async ({ page }) => {
  await fixture(page);
  const input = await openComposer(page);
  await input.pressSequentially('Hi @');
  await expect(options(page).first()).toBeVisible();
  await input.press('ArrowDown');
  const second = options(page).nth(1);
  await expect(second).toHaveAttribute('aria-selected', 'true');
  await expect(second).toHaveClass(/\bactive\b/);
  await expect(input).toHaveAttribute('aria-activedescendant', await second.getAttribute('id'));
  const active = await style(second);
  const idle = await style(options(page).first());
  expect(active.background).not.toBe(idle.background);
  expect(active.bar).not.toBe(idle.bar);
  const label = await second.textContent();
  await input.press('Enter');
  await expect(list(page)).toBeHidden();
  await expect(input).toHaveValue(`Hi ${label} `);
});

test('"@": cycling keeps the active option scrolled into view, and hovering moves the highlight', async ({ page }) => {
  await fixture(page);
  const input = await openComposer(page);
  await page.setViewportSize({ width: 900, height: 420 });
  await input.pressSequentially('@');
  await expect(options(page).first()).toBeVisible();
  const count = await options(page).count();
  for (let step = 1; step < count; step += 1) await input.press('ArrowDown');
  const last = options(page).nth(count - 1);
  await expect(last).toHaveClass(/\bactive\b/);
  await expect(last).toBeInViewport();
  const inside = await last.evaluate((node) => {
    const box = node.getBoundingClientRect();
    const frame = node.parentElement.getBoundingClientRect();
    return box.top >= frame.top - 1 && box.bottom <= frame.bottom + 1;
  });
  expect(inside).toBe(true);
  // Wrapping back to the top scrolls back up.
  await input.press('ArrowDown');
  await expect(options(page).first()).toHaveClass(/\bactive\b/);
  expect(await list(page).evaluate((node) => node.scrollTop)).toBe(0);
  // The pointer and the keyboard share one highlight.
  await options(page).nth(2).hover();
  await expect(options(page).nth(2)).toHaveClass(/\bactive\b/);
  await expect(list(page).locator('.active')).toHaveCount(1);
});

test('"/": the same visible active option, and Enter inserts the command', async ({ page }) => {
  await fixture(page);
  const input = await openComposer(page);
  await input.pressSequentially('/');
  const commands = page.locator('[role="listbox"]:not(.hidden) [role="option"]');
  await expect(commands.first()).toBeVisible();
  await input.press('ArrowDown');
  const second = commands.nth(1);
  await expect(second).toHaveClass(/\bactive\b/);
  expect((await style(second)).background).not.toBe((await style(commands.first())).background);
  const usage = (await second.locator('code').textContent()).split(' ')[0];
  await input.press('Enter');
  await expect(input).toHaveValue(`${usage} `);
});

for (const theme of ['light', 'dark']) {
  test(`${theme}: in every scheme the active option has a 3:1 accent bar and its own background`, async ({ page }) => {
    await fixture(page);
    const input = await openComposer(page);
    for (const scheme of SCHEMES) {
      await page.evaluate(([chosenScheme, chosenTheme]) => {
        const root = document.documentElement;
        if (chosenScheme === 'lantern') root.removeAttribute('data-scheme'); else root.setAttribute('data-scheme', chosenScheme);
        root.setAttribute('data-theme', chosenTheme);
      }, [scheme, theme]);
      await input.fill('');
      await input.pressSequentially('@');
      await expect(options(page).first()).toHaveClass(/\bactive\b/);
      const active = await style(options(page).first());
      const idle = await style(options(page).nth(1));
      const listBackground = await list(page).evaluate((node) => getComputedStyle(node).backgroundColor);
      expect(active.background, scheme).not.toBe(idle.background);
      expect(parseFloat(active.barWidth), scheme).toBeGreaterThanOrEqual(3);
      expect(await contrastIn(page, active.bar, listBackground), `${scheme} bar vs list`).toBeGreaterThanOrEqual(3);
      expect(await contrastIn(page, active.bar, active.background), `${scheme} bar vs option`).toBeGreaterThanOrEqual(3);
      await input.press('Escape');
    }
  });
}

test('forced colours: the active option uses the system highlight', async ({ page, browserName }) => {
  test.skip(browserName !== 'chromium', 'forced-colors emulation is Chromium-only');
  await page.emulateMedia({ forcedColors: 'active' });
  await fixture(page);
  const input = await openComposer(page);
  await input.pressSequentially('@');
  const first = options(page).first();
  await expect(first).toHaveClass(/\bactive\b/);
  const outline = await first.evaluate((node) => getComputedStyle(node).outlineStyle);
  expect(outline).toBe('solid');
  expect((await style(first)).background).not.toBe((await style(options(page).nth(1))).background);
});
