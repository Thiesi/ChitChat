import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

// #351: a merge once left a whole feature block inside another feature's
// @media (max-width: 24rem): git kept the shared closing brace outside the
// conflict, every narrow test passed, and only a desktop design read caught
// it. These checks make that shape fail fast, in every stylesheet.
const DIRECTORY = fileURLToPath(new URL('../../public/assets/css/', import.meta.url));

/** Walks a stylesheet: braces and at-rules by depth, comments by depth, never inside strings. */
export function structure(source) {
  const nestedAtRules = [];
  const nestedSections = [];
  let depth = 0;
  let line = 1;
  for (let i = 0; i < source.length; i += 1) {
    const char = source[i];
    if (char === '\n') line += 1;
    if (char === '/' && source[i + 1] === '*') {
      const end = source.indexOf('*/', i + 2);
      const comment = source.slice(i, end === -1 ? source.length : end + 2);
      // A "/* ---- Section ---- */" header starts a top-level part of the file.
      if (depth > 0 && /^\/\*\s*-{3,}/.test(comment)) nestedSections.push(line);
      line += (comment.match(/\n/g) ?? []).length;
      i += comment.length - 1;
      continue;
    }
    if (char === '"' || char === "'") {
      const end = source.indexOf(char, i + 1);
      i = end === -1 ? source.length : end;
      continue;
    }
    if (char === '{') depth += 1;
    else if (char === '}') depth -= 1;
    else if (char === '@' && depth > 0 && /^@(media|supports|container|keyframes|layer)\b/.test(source.slice(i, i + 12))) {
      nestedAtRules.push(line);
    }
  }
  return { depth, nestedAtRules, nestedSections };
}

test('every stylesheet balances its braces, nests no at-rule, and keeps section headers at the top level', () => {
  const problems = [];
  for (const name of readdirSync(DIRECTORY).filter((file) => file.endsWith('.css'))) {
    const { depth, nestedAtRules, nestedSections } = structure(readFileSync(join(DIRECTORY, name), 'utf8'));
    if (depth !== 0) problems.push(`${name}: braces unbalanced by ${depth}`);
    for (const line of nestedAtRules) problems.push(`${name}:${line}: an at-rule inside another block`);
    for (const line of nestedSections) problems.push(`${name}:${line}: a section header inside a block`);
  }
  assert.deepEqual(problems, []);
});

test('the checks catch the shape that slipped through', () => {
  const slipped = `@media (max-width: 24rem) {
  .a { color: red; }

/* ---- Notices first ---- */
.b { color: blue; }
@media (max-width: 47.99rem) { .c { display: none; } }
}`;
  assert.deepEqual(structure(slipped), { depth: 0, nestedAtRules: [6], nestedSections: [4] });
  assert.equal(structure('.a { color: red; }\n.b {').depth, 1);
  assert.deepEqual(structure('.a { content: "{"; }\n/* { */\n@media (x) { .b { color: red; } }'), { depth: 0, nestedAtRules: [], nestedSections: [] });
});
