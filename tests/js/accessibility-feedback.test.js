import assert from 'node:assert/strict';
import { test } from 'node:test';
import { feedbackDestination } from '../../public/assets/js/accessibility-feedback.js';

// #219: "Report an accessibility problem" says where it leads.

test('the project form opens GitHub in a new tab', () => {
  assert.deepEqual(
    feedbackDestination('https://github.com/Thiesi/ChitChat/issues/new?template=accessibility.yml'),
    { href: 'https://github.com/Thiesi/ChitChat/issues/new?template=accessibility.yml', where: 'GitHub', newTab: true },
  );
});

test('an operator page names its host, without www.', () => {
  assert.deepEqual(feedbackDestination('https://www.example.org/help'), { href: 'https://www.example.org/help', where: 'example.org', newTab: true });
  assert.equal(feedbackDestination('https://help.example.org/a11y').where, 'help.example.org');
});

test('a mailbox says email and stays in this tab', () => {
  assert.deepEqual(feedbackDestination('mailto:access@example.org?subject=Accessibility'), { href: 'mailto:access@example.org?subject=Accessibility', where: 'email', newTab: false });
});

test('nothing, or anything unsafe, gives no link', () => {
  for (const value of [null, undefined, '', 'mailto:', 'http://example.org', 'javascript:alert(1)', 'data:text/html,x', 'example.org', 42]) {
    assert.equal(feedbackDestination(value), null, String(value));
  }
});
