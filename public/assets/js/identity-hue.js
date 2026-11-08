// Identity hues for rooms ("lanterns") and people ("pebbles"), shared with
// ChitChat Mobile so a room or person has the same colour in both clients.
//
// Provenance: Thiesi/ChitChat-Mobile @ e9eb5b452ba23c316e1962e7993fe09010ff9933,
// docs/design/visual-vocabulary.md, "Identity hues". The order of the hues,
// the function and the test vectors change only through paired PRs in both
// repositories.

/** The eight hues, in index order. Text on every hue is always #14111C. */
export const IDENTITY_HUES = Object.freeze([
  Object.freeze({ name: 'apricot', hex: '#F7A072' }),
  Object.freeze({ name: 'honey', hex: '#F5C451' }),
  Object.freeze({ name: 'lime', hex: '#A8D672' }),
  Object.freeze({ name: 'sea', hex: '#7FD1C1' }),
  Object.freeze({ name: 'sky', hex: '#8DB8F5' }),
  Object.freeze({ name: 'lilac', hex: '#B7A3F5' }),
  Object.freeze({ name: 'rose', hex: '#F29BC4' }),
  Object.freeze({ name: 'clay', hex: '#D9B08C' }),
]);

export const IDENTITY_INK = '#14111C';

/**
 * index = (id XOR (id >>> 32)) mod 8, computed without BigInt as
 * (id mod 8) XOR (floor(id / 2^32) mod 8), exact for IDs below 2^53.
 * Room IDs and account IDs use the same function; shapes tell them apart.
 * Returns null for anything that isn't a non-negative safe integer.
 */
export function identityHueIndex(id) {
  if (!Number.isSafeInteger(id) || id < 0) return null;
  return (id % 8) ^ (Math.floor(id / 2 ** 32) % 8);
}

/** The hue for a room or account ID, or null when the ID is unusable. */
export function identityHue(id) {
  const index = identityHueIndex(id);
  return index === null ? null : IDENTITY_HUES[index];
}
