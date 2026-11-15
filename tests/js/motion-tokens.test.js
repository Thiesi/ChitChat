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

test('no other stylesheet sets a motion duration, so the cut can’t be undone', () => {
  const setters = [];
  for (const file of readdirSync(CSS).filter((name) => name.endsWith('.css') && name !== 'tokens.css')) {
    const css = readFileSync(new URL(file, CSS), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
    for (const name of DURATIONS) if (new RegExp(`${name}\\s*:`).test(css)) setters.push(`${file}: ${name}`);
  }
  assert.deepEqual(setters, []);
});
