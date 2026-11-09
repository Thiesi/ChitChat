import assert from 'node:assert/strict';
import fs from 'node:fs';
import { test } from 'node:test';
import { mentionCandidates, mentionTokens, resolveMention } from '../../public/assets/js/mention-matching.js';
import { parseInline } from '../../public/assets/js/message-format.js';

const cases = JSON.parse(fs.readFileSync(new URL('../fixtures/mention-punctuation.json', import.meta.url), 'utf8'));

for (const sample of cases) {
  test('shared mention fallback: ' + sample.body, () => {
    const known = new Set(sample.known);
    const found = [...new Set([...mentionTokens(sample.body)]
      .map(token => resolveMention(token.raw, name => known.has(name), sample.broadcast))
      .filter(token => token !== null))];
    assert.deepEqual(found, sample.expected);
    // Resolving a mention does not consume its sentence punctuation or alter its text.
    const spans = [...mentionTokens(sample.body)].flatMap(token => {
      const name = resolveMention(token.raw, value => known.has(value), sample.broadcast);
      return name === null ? [] : ['@' + token.raw.slice(0, name.length)];
    });
    assert.deepEqual(spans, sample.spans);
  });
}

test('fallback visits each valid trailing-punctuation spelling in longest-first order', () => {
  assert.deepEqual(mentionCandidates('ADA.-_'), ['ada.-_', 'ada.-', 'ada.', 'ada']);
  assert.deepEqual(mentionCandidates('ab.'), ['ab.']);
  assert.deepEqual(mentionCandidates('x'.repeat(33)), []);
});

test('formatter preserves mention words containing underscores and punctuation', () => {
  for (const sample of ['@ada_.', '@a.b-c_9.-', '@room.', 'x@ada.de']) {
    assert.deepEqual(parseInline(sample), [{ type: 'text', text: sample }]);
  }
});
