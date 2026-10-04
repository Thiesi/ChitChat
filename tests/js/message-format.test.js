import assert from 'node:assert/strict';
import { test } from 'node:test';
import { parseInline, parseMessage, safeLinkTarget } from '../../public/assets/js/message-format.js';

const text = (value) => ({ type: 'text', text: value });

test('ordinary text stays exactly as typed', () => {
  for (const sample of [
    'just chatting',
    '2 * 3 * 4 = 24',
    'snake_case_names and file_name.txt',
    'a * lonely star and an _ underscore',
    '**',
    'price: $5 *before* tax'.replace('*before*', '* before *'),
    'mail me at someone@example.org',
    'emoji 🎉 and ümlauts',
  ]) {
    assert.deepEqual(parseInline(sample), [text(sample)], sample);
  }
});

test('bold, italic and code hug their words', () => {
  assert.deepEqual(parseInline('so *very* nice'), [text('so '), { type: 'strong', children: [text('very')] }, text(' nice')]);
  assert.deepEqual(parseInline('**also** bold'), [{ type: 'strong', children: [text('also')] }, text(' bold')]);
  assert.deepEqual(parseInline('_quietly_ said'), [{ type: 'em', children: [text('quietly')] }, text(' said')]);
  assert.deepEqual(parseInline('run `npm *test*` now'), [text('run '), { type: 'code', text: 'npm *test*' }, text(' now')]);
  assert.deepEqual(parseInline('*bold _and italic_*'), [{ type: 'strong', children: [text('bold '), { type: 'em', children: [text('and italic')] }] }]);
});

test('markers that do not close stay text', () => {
  assert.deepEqual(parseInline('*not closed'), [text('*not closed')]);
  assert.deepEqual(parseInline('* spaced *'), [text('* spaced *')]);
  assert.deepEqual(parseInline('*across\nlines*'), [text('*across\nlines*')]);
  assert.deepEqual(parseInline('``'), [text('``')]);
});

test('mentions are one word, even with underscores', () => {
  assert.deepEqual(parseInline('hi @some_user_name!'), [text('hi @some_user_name!')]);
  assert.deepEqual(parseInline('_hi @bob_'), [{ type: 'em', children: [text('hi @bob')] }]);
});

test('links are http(s) only and leave trailing punctuation outside', () => {
  assert.deepEqual(parseInline('see https://example.org/a_b?c=1.'), [
    text('see '),
    { type: 'link', href: 'https://example.org/a_b?c=1', text: 'https://example.org/a_b?c=1' },
    text('.'),
  ]);
  assert.deepEqual(parseInline('(https://en.wikipedia.org/wiki/Tea_(drink))'), [
    text('('),
    { type: 'link', href: 'https://en.wikipedia.org/wiki/Tea_(drink)', text: 'https://en.wikipedia.org/wiki/Tea_(drink)' },
    text(')'),
  ]);
  for (const sample of ['javascript:alert(1)', 'data:text/html,hi', 'ftp://example.org', 'xhttps://example.org', 'https://']) {
    assert.ok(parseInline(sample).every((node) => node.type === 'text'), sample);
  }
  assert.equal(safeLinkTarget('javascript:alert(1)'), null);
  assert.equal(safeLinkTarget('HTTPS://Example.org'), 'https://example.org/');
});

test('code blocks and quotes are blocks', () => {
  assert.deepEqual(parseMessage('look:\n```\nif (a) {\n  *b*\n}\n```\ndone'), [
    { type: 'paragraph', children: [text('look:')] },
    { type: 'codeblock', text: 'if (a) {\n  *b*\n}' },
    { type: 'paragraph', children: [text('done')] },
  ]);
  assert.deepEqual(parseMessage('> wise words\n> *twice*\nreply'), [
    { type: 'quote', children: [text('wise words\n'), { type: 'strong', children: [text('twice')] }] },
    { type: 'paragraph', children: [text('reply')] },
  ]);
  // An unclosed fence is just text.
  assert.deepEqual(parseMessage('```\nnot closed'), [{ type: 'paragraph', children: [text('```\nnot closed')] }]);
});
