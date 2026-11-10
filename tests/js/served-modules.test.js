// Browsers refuse module scripts served with a non-JavaScript MIME type,
// and many servers' MIME tables (nginx's on CI among them) lack .mjs, so
// every module the web client loads uses a .js name (#204).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const PUBLIC = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../public');

test('no .mjs files are served from public/', () => {
  const found = readdirSync(PUBLIC, { recursive: true }).filter((name) => String(name).endsWith('.mjs'));
  assert.deepEqual(found, []);
});
