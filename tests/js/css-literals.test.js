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
