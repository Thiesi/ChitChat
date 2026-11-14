import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// S7 (#173): the error and not-found pages. Every unknown path used to show
// the chat page with a 200 (the web server sends them all to index.php); now
// it's a real 404 in Lantern: a plain sentence, what still works, and a way
// back to Rooms. not-found.php is the same page for an operator's
// ErrorDocument / error_page. The 500 page's markup is unit-tested
// (StatusPageTest); it shares this page's styles.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

test('an unknown path is a real 404 with the way back, and the chat stays at /', async ({ page }) => {
  for (const path of ['/no-such-page', '/rooms/garden', '/not-found.php']) {
    const response = await page.goto(path);
    expect(response.status(), path).toBe(404);
    await expect(page.getByRole('heading', { level: 1, name: 'There’s nothing here' })).toBeVisible();
    await expect(page.getByText('This address doesn’t lead to a page. It may have been typed wrong, or the link is old.')).toBeVisible();
    await expect(page.getByText('Your rooms and messages are where you left them.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Go to Rooms' })).toHaveAttribute('href', '/');
    await expect(page).toHaveTitle(/^Page not found · /);
  }
  const home = await page.goto('/?room_id=1');
  expect(home.status()).toBe(200);
  await expect(page.locator('#chat-shell, #auth-shell, #auth-screen').first()).toBeAttached();
  expect((await page.request.get('/index.php')).status()).toBe(200);
});

for (const [scheme, theme] of [['lantern', 'light'], ['lantern', 'dark'], ['dusk', 'dark']]) {
  test(`${scheme} ${theme}: the not-found page passes axe at 1280 and 360, and reflows at 320`, async ({ page }) => {
    await page.addInitScript(([chosenScheme, chosenTheme]) => {
      localStorage.setItem('chitchat-theme', chosenTheme);
      localStorage.setItem('chitchat-scheme', chosenScheme);
    }, [scheme, theme]);
    for (const width of [1280, 360]) {
      await page.setViewportSize({ width, height: 800 });
      await page.goto('/no-such-page');
      await expect(page.locator('main.status-page')).toBeVisible();
      const result = await new AxeBuilder({ page }).withTags(WCAG).analyze();
      expect(result.violations, `${width}: ${result.violations.map((violation) => `${violation.id} ${violation.nodes.map((node) => node.target).join(' ')}`).join('; ')}`).toEqual([]);
    }
    await page.setViewportSize({ width: 320, height: 640 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  });
}

test('the 404 carries the security headers and no-store; HEAD answers 404 too; // is the chat (#345)', async ({ page, baseURL }) => {
  const response = await page.request.get('/no-such-page', { headers: { Accept: 'text/html' } });
  expect(response.status()).toBe(404);
  const headers = response.headers();
  expect(headers['content-security-policy']).toContain("default-src 'self'");
  expect(headers['cache-control']).toContain('no-store');
  expect(headers['x-content-type-options']).toBe('nosniff');
  expect((await page.request.head('/no-such-page')).status()).toBe(404);
  expect((await page.request.get(`${baseURL.replace(/\/+$/, '')}//`, { headers: { Accept: 'text/html' } })).status()).toBe(200);
});

test('an API address asked for as a page still answers in JSON (#345)', async ({ page }) => {
  const response = await page.request.get('/api/v1/session.php', { headers: { Accept: 'text/html' } });
  expect(response.headers()['content-type']).toContain('application/json');
  expect(await response.text()).not.toContain('status-page');
});
