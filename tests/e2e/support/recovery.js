import { expect } from '@playwright/test';

// A password registration shows the new account's recovery key once, before
// the chat opens (#155). Journeys that register through the UI pass this step
// the way a person does: read the key, tick "I've saved…", continue.

/** 24 Crockford Base32 characters in six groups of four (docs/api/password-recovery.md). */
export const RECOVERY_KEY_FORMAT = /^[0-9A-HJKMNP-TV-Z]{4}(?:-[0-9A-HJKMNP-TV-Z]{4}){5}$/;

/**
 * Completes the one-time recovery-key step after "Create account" and
 * returns the key that was shown. Ends with the chat visible.
 */
export async function completeRecoveryKeyStep(page) {
  const step = page.getByRole('region', { name: 'Save your recovery key' });
  await expect(step).toBeVisible({ timeout: 20_000 });
  const groups = await step.locator('.recovery-key span').allTextContents();
  const key = groups.map((group) => group.trim()).join('-');
  expect(key).toMatch(RECOVERY_KEY_FORMAT);
  await step.getByRole('checkbox', { name: /saved my recovery key/ }).check();
  await step.getByRole('button', { name: /^Continue to / }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
  return key;
}
