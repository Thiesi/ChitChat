import { expect } from '@playwright/test';

/**
 * Opens a You section from its Settings list and waits until the section shows.
 *
 * A click in the first moments after the list appears can land on nothing in
 * Firefox: it hit-tests against the frame painted just before (the full-page
 * "Loading account…"). A Firefox trace on #319 showed it: the sections had
 * started, the click went to the Account row's coordinates, and no click
 * handler or hash change ran. Nobody clicks that fast, so the journeys check
 * that the section opened and click again if it didn't.
 */
export async function openYouSection(page, name) {
  const title = page.locator('#you-section-title');
  await expect(async () => {
    await page.getByRole('navigation', { name: 'Settings' }).getByRole('link', { name, exact: true }).click();
    await expect(title).toHaveText(name, { timeout: 2_000 });
  }).toPass({ timeout: 20_000 });
}
