import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { test } from 'node:test';

// Lantern Next (L0, #382): everything the overhaul animates is timed with the
// --motion-* tokens, and tokens.css cuts them to nothing under
// prefers-reduced-motion. tokens.css is the one stylesheet every page loads;
// accessibility.css, which also cuts every animation and transition, is not
// loaded by the stewardship pages, step-up-complete or the status pages.
const CSS = new URL('../../public/assets/css/', import.meta.url);
const tokens = readFileSync(new URL('tokens.css', CSS), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');

const DURATIONS = ['--motion-fast', '--motion-base', '--motion-slow'];

/** The body of the first top-level block that starts with `opener`, braces balanced. */
function block(source, opener) {
  const start = source.indexOf(opener);
  if (start === -1) return null;
  let depth = 0;
  for (let i = source.indexOf('{', start); i < source.length; i += 1) {
    if (source[i] === '{') depth += 1;
    if (source[i] === '}') {
      depth -= 1;
      if (depth === 0) return source.slice(source.indexOf('{', start) + 1, i);
    }
  }
  return null;
}

/** A CSS time in seconds. */
function seconds(value) {
  const match = /^(\d*\.?\d+)(ms|s)$/.exec(value.trim());
  assert.ok(match, `not a time: ${value}`);
  return Number(match[1]) / (match[2] === 'ms' ? 1000 : 1);
}

test('every motion duration has a default', () => {
  for (const name of DURATIONS) {
    const value = new RegExp(`${name}:\\s*([^;]+);`).exec(tokens)?.[1];
    assert.ok(value, name);
    assert.ok(seconds(value) > 0.001, `${name} is a real duration by default`);
  }
});

test('reduced motion cuts every motion duration to 0.001s or less, in tokens.css', () => {
  const reduced = block(tokens, '@media (prefers-reduced-motion: reduce)');
  assert.ok(reduced, 'tokens.css has a prefers-reduced-motion block');
  assert.match(reduced, /:root\s*\{/, 'it sets the tokens on :root');
  for (const name of DURATIONS) {
    const value = new RegExp(`${name}:\\s*([^;]+);`).exec(reduced)?.[1];
    assert.ok(value, `${name} is overridden under reduced motion`);
    assert.ok(seconds(value) <= 0.001, `${name} is ${value}`);
  }
});

// Lantern Next L9 (#401): rules timed with a literal instead of a token, and page-to-page
// view transitions, are cut on every page too. accessibility.css says the same, but the
// stewardship and status pages don't load it.
test('reduced motion also cuts literal animations, transitions and view transitions, in tokens.css', () => {
  const reduced = block(tokens, '@media (prefers-reduced-motion: reduce)');
  const everything = block(reduced, '*,');
  assert.ok(everything, 'a rule for every element and pseudo-element');
  assert.match(reduced, /\*,\s*\*::before,\s*\*::after\s*\{/, 'it covers ::before and ::after');
  for (const property of ['animation-duration', 'transition-duration']) {
    const value = new RegExp(`${property}:\\s*([^;!]+)\\s*!important;`).exec(everything)?.[1];
    assert.ok(value, `${property} is cut, with !important`);
    assert.ok(seconds(value) <= 0.001, `${property} is ${value}`);
  }
  assert.match(everything, /animation-iteration-count:\s*1\s*!important;/, 'nothing loops');
  const transitions = block(reduced, '::view-transition-group(*)');
  assert.ok(transitions, 'a rule for view transitions');
  for (const part of ['::view-transition-group(*)', '::view-transition-old(*)', '::view-transition-new(*)']) assert.ok(reduced.includes(part), part);
  const value = /animation-duration:\s*([^;!]+)\s*!important;/.exec(transitions)?.[1];
  assert.ok(value && seconds(value) <= 0.001, `view transitions are ${value}`);
});

// Lantern Next L10 (#404): the page-to-page cross-fade exists only where motion is welcome,
// and is short.
test('the page cross-fade is declared only under prefers-reduced-motion: no-preference', () => {
  const welcome = block(tokens, '@media (prefers-reduced-motion: no-preference)');
  assert.ok(welcome, 'tokens.css has a no-preference block');
  assert.match(welcome, /@view-transition\s*\{\s*navigation:\s*auto;\s*\}/, 'the cross-fade is in it');
  const outside = tokens.replace(welcome, '');
  assert.doesNotMatch(outside, /@view-transition/, 'and nowhere else in tokens.css');
  const value = /::view-transition-new\(root\)\s*\{\s*animation-duration:\s*([^;]+);/.exec(welcome)?.[1];
  assert.ok(value && seconds(value) <= 0.2, `it lasts ${value}`);
  for (const file of readdirSync(CSS).filter((name) => name.endsWith('.css') && name !== 'tokens.css')) {
    const css = readFileSync(new URL(file, CSS), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
    assert.doesNotMatch(css, /@view-transition/, `${file} declares none`);
  }
});

test('no other stylesheet sets a motion duration, so the cut can’t be undone', () => {
  const setters = [];
  for (const file of readdirSync(CSS).filter((name) => name.endsWith('.css') && name !== 'tokens.css')) {
    const css = readFileSync(new URL(file, CSS), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
    for (const name of DURATIONS) if (new RegExp(`${name}\\s*:`).test(css)) setters.push(`${file}: ${name}`);
  }
  assert.deepEqual(setters, []);
});
