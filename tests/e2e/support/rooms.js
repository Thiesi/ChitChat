import { expect } from '@playwright/test';

/**
 * Opens a room from the sidebar. The room list is rebuilt live (a room was
 * just created elsewhere, say), and a click landing just as it is replaced
 * goes nowhere, so click until the room is really open.
 */
export async function openRoom(page, name) {
  await expect(async () => {
    await page.locator('.room-button', { hasText: name }).click();
    await expect(page.locator('#room-title')).toHaveText(`# ${name}`, { timeout: 2_000 });
  }).toPass({ timeout: 20_000 });
}
