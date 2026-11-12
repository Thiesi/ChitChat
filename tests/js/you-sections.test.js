import assert from 'node:assert/strict';
import { test } from 'node:test';
import { SECTIONS, sectionForAddress } from '../../public/assets/js/you-sections.js';

// S5a (#171): which You section an address opens.

test('a section hash opens that section', () => {
  for (const key of SECTIONS) assert.equal(sectionForAddress({ hash: `#${key}` }), key);
});

test('an old heading anchor opens the section holding it', () => {
  const holders = { 'appearance-heading': 'appearance', 'account-closure-heading': 'account' };
  const find = (id) => holders[id] ?? null;
  assert.equal(sectionForAddress({ hash: '#appearance-heading' }, find), 'appearance');
  assert.equal(sectionForAddress({ hash: '#account-closure-heading' }, find), 'account');
  assert.equal(sectionForAddress({ hash: '#nowhere' }, find), null);
});

test('deep links from other pages open their section', () => {
  assert.equal(sectionForAddress({ search: '?picture=ready' }), 'profile');
  assert.equal(sectionForAddress({ search: '?picture=failed&sign_in_error=x' }), 'profile');
  assert.equal(sectionForAddress({ search: '?connected=google' }), 'security');
  assert.equal(sectionForAddress({ search: '?sign_in_error=taken' }), 'security');
  assert.equal(sectionForAddress({ search: '?recovery=create' }), 'security');
  assert.equal(sectionForAddress({}), null);
});
