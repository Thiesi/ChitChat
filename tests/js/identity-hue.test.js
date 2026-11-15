import assert from 'node:assert/strict';
import { test } from 'node:test';
import { IDENTITY_HUES, identityHue, identityHueIndex, roomInitial } from '../../public/assets/js/identity-hue.js';

// The shared vectors, copied verbatim from Thiesi/ChitChat-Mobile @ e9eb5b4,
// docs/design/visual-vocabulary.md ("Identity hues"). CCM pins the same ones,
// so both clients give a room or person the same colour.
const VECTORS = [
  [0, 0, 'apricot'],
  [1, 1, 'honey'],
  [7, 7, 'clay'],
  [9, 1, 'honey'],
  [1234567, 7, 'clay'],
  [4294967296, 1, 'honey'],
  [4294967297, 0, 'apricot'],
];

test('the shared identity-hue vectors match CCM', () => {
  for (const [id, index, name] of VECTORS) {
    assert.equal(identityHueIndex(id), index, `index for ${id}`);
    assert.equal(identityHue(id).name, name, `hue for ${id}`);
  }
});

test('the hue order is the shared one', () => {
  assert.deepEqual(IDENTITY_HUES.map(({ name }) => name), ['apricot', 'honey', 'lime', 'sea', 'sky', 'lilac', 'rose', 'clay']);
});

test('the JS-safe form equals (id XOR (id >>> 32)) mod 8 across the safe range', () => {
  const samples = [2 ** 31 - 1, 2 ** 31, 2 ** 32 - 1, 2 ** 33 + 5, 2 ** 40 + 123, Number.MAX_SAFE_INTEGER];
  for (const id of samples) {
    const big = BigInt(id);
    const expected = Number((big ^ (big >> 32n)) % 8n);
    assert.equal(identityHueIndex(id), expected, `id ${id}`);
  }
});

test('unusable IDs give no hue instead of a wrong one', () => {
  for (const id of [-1, 1.5, Number.NaN, 2 ** 53, '7', null, undefined]) {
    assert.equal(identityHueIndex(id), null, String(id));
    assert.equal(identityHue(id), null, String(id));
  }
});

// Lantern Next L1 (#384): a room's lantern shows its initial. Web first; recorded
// for CCM in docs/design/lantern-next.md.
test('a room’s initial is its first character as a person counts it, in upper case', () => {
  for (const [name, initial] of [
    ['Garden', 'G'],
    ['  the living room', 'T'],
    ['éclair', 'É'],
    ['日本語', '日'],
    ['42 things', '4'],
    ['🌿 Greenhouse', '🌿'],
    ['👨‍👩‍👧 family', '👨‍👩‍👧'],
    ['école', 'É'],
  ]) {
    assert.equal(roomInitial(name), initial, name);
  }
});

test('a room without a usable name keeps "#"', () => {
  for (const name of ['', '   ', null, undefined]) assert.equal(roomInitial(name), '#', String(name));
});
