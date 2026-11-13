import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

// S7 (#173): every question the web asks is a styled dialog or an inline
// editor, never the browser's own window.confirm, window.prompt or alert.
// This keeps new ones from coming back.
const DIRECTORY = fileURLToPath(new URL('../../public/assets/js/', import.meta.url));
const NATIVE = /(?:\bwindow\s*\.\s*|(?<![.\w$]))(?:confirm|prompt|alert)\s*\(/u;

function withoutComments(source) {
  return source.replace(/\/\*[\s\S]*?\*\//gu, '').replace(/(^|[^:'"`])\/\/.*$/gmu, '$1');
}

test('no window.confirm, window.prompt or alert( in public/assets/js', () => {
  const found = [];
  for (const name of readdirSync(DIRECTORY).filter((file) => file.endsWith('.js'))) {
    const lines = withoutComments(readFileSync(join(DIRECTORY, name), 'utf8')).split('\n');
    lines.forEach((line, index) => {
      if (NATIVE.test(line)) found.push(`${name}:${index + 1}: ${line.trim()}`);
    });
  }
  assert.deepEqual(found, []);
});

test('the scan would catch each form', () => {
  for (const line of ['window.confirm("x")', 'if (!confirm("x")) return;', 'window.prompt("x")', 'alert("x")', 'window . alert ("x")']) {
    assert.ok(NATIVE.test(line), line);
  }
  for (const line of ['confirmAction({})', 'await askText({})', 'dialog.confirm()', 'const confirm = 1;', 'showAlert("x")']) {
    assert.ok(!NATIVE.test(line), line);
  }
});
