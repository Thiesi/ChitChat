import assert from 'node:assert/strict';
import { test } from 'node:test';
import { refocusComposer } from '../../public/assets/js/refocus.js';

// #322: a send or upload that settles late puts focus back in the composer
// only if it is still there or was lost, never away from where the person went.

function element(name) {
  return {
    name,
    focus() { globalThis.document.activeElement = this; },
  };
}

function setup(activeName) {
  const body = element('body');
  const input = element('input');
  const send = element('send');
  const emojiButton = element('emoji button');
  const byName = { body, input, send, 'emoji button': emojiButton, none: null };
  globalThis.document = { body, activeElement: byName[activeName] };
  return { input, send };
}

for (const [active, expected] of [
  ['input', 'input'],
  ['send', 'input'],
  ['body', 'input'],
  ['none', 'input'],
  ['emoji button', 'emoji button'],
]) {
  test(`focus on ${active} → ${expected}`, () => {
    const { input, send } = setup(active);
    refocusComposer(input, send);
    assert.equal(globalThis.document.activeElement?.name, expected);
  });
}

test('a missing own control is ignored', () => {
  const { input } = setup('emoji button');
  refocusComposer(input, null, undefined);
  assert.equal(globalThis.document.activeElement.name, 'emoji button');
});
