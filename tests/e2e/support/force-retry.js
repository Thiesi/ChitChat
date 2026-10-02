import { test } from '@playwright/test';

// TEMPORARY verification hook, to be reverted before merge. Fails every test once
// after its first attempt passes, so each retry runs against the first attempt's data.
// In serial groups only the last test is failed: the retry then reruns the whole
// group, after every test in it has completed a first attempt.
const notLastInSerialGroup = new Set([
  'emits hardened HTTP headers and protects anonymous APIs',
  'supports keyboard-operated auth tabs with visible focus',
  'axe-core finds no WCAG A or AA violations on core surfaces',
  'core surfaces reflow without document-level horizontal scrolling',
  'forced-colors mode retains selected, primary-action, and focus affordances',
]);

export function forceOneRetry() {
  test.afterEach(async ({}, testInfo) => {
    if (testInfo.retry === 0 && testInfo.status === 'passed' && !notLastInSerialGroup.has(testInfo.title)) {
      throw new Error('Forced first-attempt failure to exercise the retry path (temporary).');
    }
  });
}
