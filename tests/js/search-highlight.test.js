import assert from 'node:assert/strict';
import { test } from 'node:test';
import { highlightSegments, queryTerms } from '../../public/assets/js/search-highlight.js';

// S4d (#170): highlights mirror websearch_to_tsquery('simple', …).

test('query terms: words, phrases, OR and exclusions', () => {
  assert.deepEqual(queryTerms('dice'), ['dice']);
  assert.deepEqual(queryTerms('Board Games'), ['board', 'games']);
  assert.deepEqual(queryTerms('"board games" night'), ['board', 'games', 'night']);
  assert.deepEqual(queryTerms('dice or cards'), ['dice', 'cards']);
  assert.deepEqual(queryTerms('dice -cards'), ['dice']);
  assert.deepEqual(queryTerms('dice -"card games"'), ['dice']);
  assert.deepEqual(queryTerms('Würfel straße'), ['würfel', 'straße']);
  assert.deepEqual(queryTerms('   '), []);
});

test('whole words only, case-insensitive, every hit', () => {
  const join = (segments) => segments.map((segment) => (segment.mark ? `[${segment.text}]` : segment.text)).join('');
  assert.equal(join(highlightSegments('Dice night: bring dice, not paradice.', ['dice'])), '[Dice] night: bring [dice], not paradice.');
  assert.equal(join(highlightSegments('Board games tonight', ['board', 'games'])), '[Board] [games] tonight');
  assert.equal(join(highlightSegments('No match here', ['dice'])), 'No match here');
  assert.equal(join(highlightSegments('dice', [])), 'dice');
  assert.deepEqual(highlightSegments('', ['dice']), []);
});

test('markup in the text stays text', () => {
  assert.deepEqual(highlightSegments('<b>dice</b>', ['dice']), [
    { text: '<b>', mark: false }, { text: 'dice', mark: true }, { text: '</b>', mark: false },
  ]);
});

test('the server’s ranges (UTF-16, relative to the excerpt) split the text as sent', async () => {
  const { rangeSegments } = await import('../../public/assets/js/search-highlight.js');
  assert.deepEqual(rangeSegments('bring dice, not paradice.', [{ start: 6, length: 4 }, { start: 16, length: 8 }]), [
    { text: 'bring ', mark: false }, { text: 'dice', mark: true }, { text: ', not ', mark: false }, { text: 'paradice', mark: true }, { text: '.', mark: false },
  ]);
  // An emoji is two UTF-16 units; the offsets count them, as JavaScript does.
  assert.deepEqual(rangeSegments('🎲 dice', [{ start: 3, length: 4 }]), [{ text: '🎲 ', mark: false }, { text: 'dice', mark: true }]);
});

test('unusable ranges give null, so the words are matched instead', async () => {
  const { rangeSegments } = await import('../../public/assets/js/search-highlight.js');
  assert.equal(rangeSegments('dice', undefined), null);
  assert.equal(rangeSegments('dice', []), null);
  assert.equal(rangeSegments('dice', [{ start: 2, length: 9 }]), null);
  assert.equal(rangeSegments('dice dice', [{ start: 5, length: 4 }, { start: 0, length: 4 }]), null);
  assert.equal(rangeSegments('dice', [{ start: 0, length: 0 }]), null);
  assert.equal(rangeSegments('dice', [{ start: '0', length: 4 }]), null);
});
