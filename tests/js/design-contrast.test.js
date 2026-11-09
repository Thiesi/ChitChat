import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { IDENTITY_HUES, IDENTITY_INK } from '../../public/assets/js/identity-hue.js';

// Contrast floors for every colour scheme (Lantern acceptance A-01, A-02,
// A-10, as adopted for the web in the phase-2 direction on #163). Reads the
// shipped token file the way the cascade applies it: Lantern is the :root
// base, and a scheme inherits Lantern's value for every role it doesn't set,
// in the same theme.
const SOURCE = new URL('../../public/assets/css/tokens.css', import.meta.url);

const TEXT = 4.5;      // A-01: text and icons that carry meaning
const BOUNDARY = 3;    // A-02: component boundaries and meaningful non-text marks
const IDENTITY = 8.4;  // ink on every identity hue

// [foreground, background, minimum, why]
const PAIRS = [
  ['text', 'ground', TEXT, 'body text'],
  ['text', 'surface', TEXT, 'text on cards and fields'],
  ['text', 'raised', TEXT, 'incoming bubbles'],
  ['text', 'presence-wash', TEXT, 'mention highlight'],
  ['text-muted', 'ground', TEXT, 'secondary text'],
  ['text-muted', 'surface', TEXT, 'secondary text on cards'],
  ['text-muted', 'raised', TEXT, 'times on incoming bubbles'],
  ['presence-text', 'ground', TEXT, 'honey used as text (ping labels)'],
  ['presence-text', 'surface', TEXT, 'honey used as text on cards'],
  ['on-signature', 'signature', TEXT, 'labels on persimmon (never white)'],
  ['signature-text', 'ground', TEXT, 'persimmon as text'],
  ['signature-text', 'surface', TEXT, 'persimmon as text on cards'],
  ['on-outgoing', 'outgoing', TEXT, 'your bubbles'],
  ['steward', 'ground', TEXT, 'links and stewardship'],
  ['steward', 'surface', TEXT, 'links and stewardship on cards'],
  ['error', 'ground', TEXT, 'errors'],
  ['error', 'surface', TEXT, 'errors on cards'],
  ['field-line', 'surface', BOUNDARY, 'field and outlined-button borders'],
  ['field-line', 'ground', BOUNDARY, 'field borders on the page'],
  ['unsure-line', 'ground', BOUNDARY, 'uncertain-send notice border'],
  ['unsure-line', 'outgoing', BOUNDARY, 'requested-message ring on your bubble'],
];

// Persimmon *fill* on paper is about 2.9:1, so it may never be the only cue
// (labels on buttons, numbers on badges, bold on the active tab). That is a
// review rule, not a ratio; this test documents it instead of asserting 3:1.
const KNOWN_FILL_EXCEPTION = ['signature', 'ground', 'light'];

// Maps the token file's selectors to "scheme/theme". Dark is the base theme.
function schemeKey(selector) {
  const scheme = selector.match(/\[data-scheme="([a-z-]+)"\]/)?.[1] ?? 'lantern';
  const theme = /\[data-theme="light"\]/.test(selector) && !/:not\(\[data-theme="light"\]\)/.test(selector) ? 'light' : 'dark';
  return `${scheme}/${theme}`;
}

function parseSchemes(css) {
  const schemes = new Map();
  const block = /(:root[^{]*)\{([^}]*)\}/g;
  for (const [, selector, body] of css.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(block)) {
    if (/\s[a-z]/.test(selector.trim())) continue; // descendant rules, not token blocks
    const tokens = {};
    for (const [, name, value] of body.matchAll(/--([a-z-]+)\s*:\s*(#[0-9a-fA-F]{6})\s*;/g)) tokens[name] = value;
    if (Object.keys(tokens).length) schemes.set(schemeKey(selector.trim()), tokens);
  }
  return schemes;
}

function resolved(schemes) {
  const result = new Map();
  const names = new Set([...schemes.keys()].map((key) => key.split('/')[0]));
  for (const scheme of names) {
    for (const theme of ['light', 'dark']) {
      const base = schemes.get(`lantern/${theme}`) ?? {};
      result.set(`${scheme}/${theme}`, { ...base, ...(schemes.get(`${scheme}/${theme}`) ?? {}) });
    }
  }
  return result;
}

function luminance(hex) {
  const [r, g, b] = [1, 3, 5].map((offset) => {
    const channel = parseInt(hex.slice(offset, offset + 2), 16) / 255;
    return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(a, b) {
  const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (light + 0.05) / (dark + 0.05);
}

const schemes = resolved(parseSchemes(readFileSync(SOURCE, 'utf8')));

test('the contrast formula matches WCAG reference values', () => {
  assert.equal(contrast('#000000', '#FFFFFF').toFixed(1), '21.0');
  assert.equal(contrast('#777777', '#FFFFFF').toFixed(2), '4.48');
  assert.equal(contrast('#14111C', '#F06A3B').toFixed(1), '6.1');
});

test('Lantern defines every role in both themes', () => {
  const roles = PAIRS.flatMap(([fg, bg]) => [fg, bg]).concat(['line', 'presence', 'steward']);
  for (const theme of ['light', 'dark']) {
    const tokens = schemes.get(`lantern/${theme}`);
    for (const role of new Set(roles)) assert.match(tokens[role] ?? '', /^#[0-9a-fA-F]{6}$/, `lantern/${theme} --${role}`);
  }
});

test('every scheme the picker offers has its token blocks', () => {
  for (const scheme of ['lantern', 'lounge', 'dusk', 'ember', 'rose', 'contrast']) {
    assert.ok(schemes.has(`${scheme}/light`) && schemes.has(`${scheme}/dark`), scheme);
  }
  assert.ok(schemes.has('midnight/dark'), 'midnight');
});

test('every scheme and theme meets the text and boundary floors', () => {
  const failures = [];
  for (const [key, tokens] of schemes) {
    for (const [fg, bg, minimum, why] of PAIRS) {
      const ratio = contrast(tokens[fg], tokens[bg]);
      if (ratio < minimum) failures.push(`${key}: --${fg} on --${bg} is ${ratio.toFixed(2)}:1, needs ${minimum}:1 (${why})`);
    }
  }
  assert.deepEqual(failures, []);
});

test('honey presence marks stay visible on dark grounds', () => {
  for (const [key, tokens] of schemes) {
    if (!key.endsWith('/dark')) continue;
    assert.ok(contrast(tokens.presence, tokens.ground) >= BOUNDARY, `${key}: --presence on --ground`);
  }
});

test('ink reads on every identity hue', () => {
  for (const { name, hex } of IDENTITY_HUES) {
    const ratio = contrast(IDENTITY_INK, hex);
    assert.ok(ratio >= IDENTITY, `${name}: ${ratio.toFixed(2)}:1`);
  }
});

test('the persimmon fill exception is real, so it stays a reviewed rule', () => {
  const [fill, ground, theme] = KNOWN_FILL_EXCEPTION;
  const tokens = schemes.get(`lantern/${theme}`);
  // If this ever reaches 3:1, the exception (and its review rule) can go.
  assert.ok(contrast(tokens[fill], tokens[ground]) < BOUNDARY);
});
