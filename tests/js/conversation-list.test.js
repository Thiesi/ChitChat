import assert from 'node:assert/strict';
import { test } from 'node:test';
import { conversationPreview, conversationRowLabel } from '../../public/assets/js/conversation-list.js';

// S4b (#170): the Direct list's row names and previews.

const conversation = (extra = {}) => ({
  user: { id: 2, username: 'Rowan' },
  last_message: { id: 9, body: 'See you', outgoing: false, created_at: '2026-11-12T09:00:00Z' },
  unread_count: 0,
  ...extra,
});

test('a row name is the person, then the unread count', () => {
  assert.equal(conversationRowLabel(conversation()), 'Rowan');
  assert.equal(conversationRowLabel(conversation({ unread_count: 3 })), 'Rowan, 3 unread');
  assert.equal(conversationRowLabel(conversation({ unread_count: 120 })), 'Rowan, 99+ unread');
  // A pending placeholder name (#164) is shown as it is.
  assert.equal(conversationRowLabel(conversation({ user: { id: 2, username: 'member-k7q2xm' } })), 'member-k7q2xm');
});

test('the preview prefixes your own last message with "You: "', () => {
  assert.equal(conversationPreview(conversation()), 'See you');
  assert.equal(conversationPreview(conversation({ last_message: { body: 'On my way', outgoing: true } })), 'You: On my way');
  assert.equal(conversationPreview(conversation({ last_message: null })), '');
});

test('the state comes only from the payload: Blocked, Unavailable, Account closed (#274)', async () => {
  const { conversationState } = await import('../../public/assets/js/conversation-list.js');
  const open = { blocked_by_me: false, messaging_available: true };
  // Older servers send no state: the row shows the last message.
  assert.equal(conversationState(conversation()), null);
  assert.equal(conversationState(conversation({ user: { id: 2, username: 'Rowan', available: true }, relationship: open })), null);
  assert.equal(conversationState(conversation({ user: { id: 2, username: 'Rowan', available: true }, relationship: { blocked_by_me: true, messaging_available: false } })), 'Blocked');
  // Their block is never named.
  assert.equal(conversationState(conversation({ user: { id: 2, username: 'Rowan', available: true }, relationship: { blocked_by_me: false, messaging_available: false } })), 'Unavailable');
  assert.equal(conversationState(conversation({ user: { id: 2, username: 'Rowan', available: false }, relationship: { blocked_by_me: false, messaging_available: false } })), 'Unavailable');
  assert.equal(conversationState(conversation({ user: { id: 9, username: 'Closed account #9', available: false }, relationship: { blocked_by_me: false, messaging_available: false } })), 'Account closed');
  assert.equal(conversationRowLabel(conversation({ unread_count: 2, user: { id: 2, username: 'Rowan', available: true }, relationship: { blocked_by_me: true, messaging_available: false } })), 'Rowan, 2 unread, blocked');
});
