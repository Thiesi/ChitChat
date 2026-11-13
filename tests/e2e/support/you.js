import { expect } from '@playwright/test';

const ATTEMPTS = 3;

/**
 * Opens a You section from its Settings list and waits until the section shows.
 *
 * A click in the first moments after the list appears can land on nothing in
 * Firefox: it hit-tests against the frame painted just before (the full-page
 * "Loading account…"). A Firefox trace on #319 showed it: the sections had
 * started, the click went to the Account row's coordinates, and no click
 * handler or hash change ran. Nobody clicks that fast.
 *
 * So: wait until the loading screen is gone, click, and check the section
 * title. Only that stale-paint case is retried, at most three clicks in all;
 * if the title never changes, clicking really is broken and the test says so.
 */
export async function openYouSection(page, name) {
  await expect(page.locator('#account-loading')).toBeHidden();
  const link = page.getByRole('navigation', { name: 'Settings' }).getByRole('link', { name, exact: true });
  const title = page.locator('#you-section-title');
  for (let attempt = 1; attempt <= ATTEMPTS; attempt += 1) {
    await link.click();
    try {
      await expect(title).toHaveText(name, { timeout: 2_000 });
      return;
    } catch {
      // The click landed on the frame painted before the list: try again.
    }
  }
  throw new Error(`The "${name}" section didn't open after ${ATTEMPTS} clicks on its Settings link: the section title still reads "${await title.textContent()}". Clicking a You section is broken, not just early.`);
}
