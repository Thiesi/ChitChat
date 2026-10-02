import { expect, test } from '@playwright/test';

/*
 * Retry support. A retried test runs against the same database as its failed
 * attempt, so anything that attempt created (accounts, rooms, messages,
 * notifications) is still there. Data a test creates is therefore made
 * distinct per attempt, and shared fixtures are reused rather than recreated.
 * The first attempt keeps the original names and texts unchanged.
 */

export function attemptSuffix() {
  const { retry } = test.info();
  return retry === 0 ? '' : `R${retry}`;
}

/** Message or file text that only this attempt can have produced. */
export function attemptText(text) {
  const suffix = attemptSuffix();
  return suffix === '' ? text : `${text} ${suffix}`;
}

/** Username or room key that only this attempt can have registered. */
export function attemptName(name) {
  return `${name}${attemptSuffix()}`;
}

/** A copy of a test-local account whose username only this attempt can have registered. */
export function attemptAccount(account) {
  return { ...account, username: attemptName(account.username) };
}

/**
 * Registers a shared fixture account, or signs in on a retry when an earlier
 * attempt already registered it.
 */
export async function registerOrSignIn(page, account, register) {
  if (test.info().retry > 0) {
    await page.goto('/');
    await expect(page.locator('#auth-shell')).toBeVisible();
    await page.locator('#login-username').fill(account.username);
    await page.locator('#login-password').fill(account.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();

    // Both elements are always present, so only the visible one may match.
    const chatShell = page.locator('#chat-shell:visible');
    const authError = page.locator('#auth-error:visible').filter({ hasText: /\S/ });
    await expect(chatShell.or(authError)).toBeVisible();
    if (await chatShell.count() > 0) return;
  }

  await register(page, account);
}
