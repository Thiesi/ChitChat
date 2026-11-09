import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { test } from 'node:test';

// Colour values live only in tokens.css (S0, #167; the web reading of CCM's
// P-01). Every other stylesheet uses the roles through var(--…). Comments and
// url(…) contents are ignored: an SVG mask's stroke colour is shape, not colour.
// Forced-colours system keywords (CanvasText, Highlight…) are allowed.
const DIRECTORY = new URL('../../public/assets/css/', import.meta.url);
const NAMED = /\b(white|black|red|green|blue|gray|grey|yellow|orange|purple|pink|brown|silver|navy|teal|maroon|olive|lime|aqua|fuchsia)\b/i;
const LITERAL = /#[0-9a-f]{3,8}\b|\b(rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(/i;

function declarations(css) {
  const stripped = css
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/url\((?:"[^"]*"|'[^']*'|[^)]*)\)/g, 'url()');
  // Only values (after a colon inside a block) can carry colours.
  return [...stripped.matchAll(/([a-z-]+)\s*:\s*([^;{}]+)/gi)].map(([, property, value]) => ({ property, value }));
}

test('no stylesheet except tokens.css contains a colour literal', () => {
  const found = [];
  for (const file of readdirSync(DIRECTORY).filter((name) => name.endsWith('.css') && name !== 'tokens.css')) {
    const css = readFileSync(new URL(file, DIRECTORY), 'utf8');
    // Layout custom properties (such as --sidebar-width) are fine; colour values are not.
    for (const { property, value } of declarations(css)) {
      if (LITERAL.test(value) || NAMED.test(value)) found.push(`${file}: ${property}: ${value.trim()}`);
    }
  }
  assert.deepEqual(found, []);
});

test('the scan catches what it should', () => {
  const sample = '.a { color: #fff; } .b { background: rgb(0 0 0 / 0.5); } .c { border-color: white; } '
    + '/* #160 in a comment */ .d { mask: url("data:image/svg+xml,stroke=\'black\'"); } .e { color: var(--text); }';
  const hits = declarations(sample).filter(({ value }) => LITERAL.test(value) || NAMED.test(value));
  assert.deepEqual(hits.map(({ property }) => property), ['color', 'background', 'border-color']);
});

// A stylesheet written before the role rename (from a branch that started
// before S0) silently loses its colours: var(--border) is simply unset.
// Every var(--name) without a fallback must be declared in some stylesheet,
// or set inline by a script or template (for example --avatar-hue).
test('every custom property a stylesheet uses is defined', () => {
  const files = readdirSync(DIRECTORY).filter((name) => name.endsWith('.css'));
  const sheets = files.map((file) => [file, readFileSync(new URL(file, DIRECTORY), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')]);
  const defined = new Set(sheets.flatMap(([, css]) => [...css.matchAll(/(--[a-z0-9-]+)\s*:/gi)].map((m) => m[1])));
  const inline = (directory, extension) => readdirSync(directory, { recursive: true })
    .filter((name) => name.endsWith(extension))
    .map((name) => readFileSync(new URL(name.replaceAll('\\', '/'), directory), 'utf8'));
  for (const source of [
    ...inline(new URL('../../public/assets/js/', import.meta.url), '.js'),
    ...inline(new URL('../../public/', import.meta.url), '.php'),
    ...inline(new URL('../../src/', import.meta.url), '.php'),
  ]) {
    for (const m of source.matchAll(/(--[a-z][a-z0-9-]*)\s*[:'"]/gi)) defined.add(m[1]);
  }
  const missing = [];
  for (const [file, css] of sheets) {
    for (const m of css.matchAll(/var\(\s*(--[a-z0-9-]+)\s*\)/gi)) {
      if (!defined.has(m[1])) missing.push(`${file}: ${m[1]}`);
    }
  }
  assert.deepEqual([...new Set(missing)], []);
});
