import assert from 'node:assert/strict';
import { test } from 'node:test';
import { parseInline } from '../../public/assets/js/message-format.js';
import { SHRUG, parseSlashCommand, splitTarget } from '../../public/assets/js/slash-commands.js';

test('commands are a name after a slash, then the rest', () => {
  assert.deepEqual(parseSlashCommand('/help'), { name: 'help', args: '' });
  assert.deepEqual(parseSlashCommand('  /TOPIC  Board games on Friday  '), { name: 'topic', args: 'Board games on Friday' });
  assert.deepEqual(parseSlashCommand('/dm Alex see you\nlater'), { name: 'dm', args: 'Alex see you\nlater' });
  assert.equal(parseSlashCommand('hello /help'), null);
  assert.equal(parseSlashCommand('/'), null);
  assert.equal(parseSlashCommand('/123'), null);
});

test('a target is a name, optionally with @, then a message', () => {
  assert.deepEqual(splitTarget('@Alex hi there'), { name: 'Alex', message: 'hi there' });
  assert.deepEqual(splitTarget('Sam'), { name: 'Sam', message: '' });
  assert.equal(splitTarget(''), null);
});

test('the shrug survives formatting', () => {
  assert.deepEqual(parseInline(`oh well ${SHRUG}`), [{ type: 'text', text: `oh well ${SHRUG}` }]);
  assert.deepEqual(parseInline('a \\*literal\\* star'), [{ type: 'text', text: 'a \\*literal\\* star' }]);
});
