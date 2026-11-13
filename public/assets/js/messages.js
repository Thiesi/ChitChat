import { ApiError, apiGet, apiPost, isPageLeft, logError, setCsrfToken } from './api.js';
import { announce, announceIncoming, markKnown, resetAnnouncements } from './announcer.js';
import { controlItem, moreButton, reactButton, reconcile, runPositions, signatureOf, withDays } from './transcript.js';
import { attachTranscriptKeys } from './transcript-keys.js';
import { attachEmojiPicker } from './emoji-picker.js';
import { renderMessageBody, buildReplyPreview, buildReactionBar } from './message-content.js';
import { attachMentionAutocomplete } from './mention-autocomplete.js';
import { attachNameCompletion } from './name-completion.js';
import { alertUser } from './attention.js';
import { formatDateTime } from './datetime.js';
import { createTypingIndicator, createTypingSignal } from './typing.js';
import { openPersonReportDialog } from './message-report-dialog.js';
import { canReport } from './person-reports.js';
import { startRulesGate } from './rules.js';
import { REVOKED_NOTICE, rememberSignedOutNotice } from './signed-out-notice.js';
import { MESSAGE_NOT_SENT, errorText, failedSending } from './unavailable.js';
import { initials } from './avatar.js';
import { identityHueIndex } from './identity-hue.js';
import { phoneListShowing } from './pane-stack.js';
import { renamedUser, renameInMessages } from './renames.js';
import { renderConversationList as drawConversationList } from './conversation-list.js';
import { startUsernameGate } from './username-gate.js';
import { LONG_OUTAGE_NOTICE, keepEventStream, sessionProbe } from './realtime-stream.js';
import { createConnectionStatus } from './connection-status.js';

let transcriptKeys = null;
const state = {
  user: null,
  privacy: null,
  conversations: [],
  selectedUser: null,
  relationship: null,
  messages: [],
  messageIds: new Set(),
  oldestMessageId: null,
  stream: null,
  settledResume: false,
  replyTo: null,
};
const elements = {};
let mentionAutocomplete = null;
let peerTyping = null;
let typingSignal = null;

window.addEventListener('DOMContentLoaded', () => {
  bindElements();
  bindEvents();
  bootstrap().catch(handleFatal);
});

function bindElements() {
  for (const id of [
    'messages-loading', 'messages-shell', 'messages-identity', 'dm-privacy-text',
    'messages-error', 'dm-user-search-form', 'dm-user-search', 'dm-user-results',
    'dm-conversation-list', 'dm-peer-name', 'dm-peer-status', 'dm-block-toggle', 'dm-report-person',
    'dm-empty-state', 'dm-message-list', 'dm-load-older', 'dm-composer', 'dm-emoji-button',
    'dm-message-input', 'dm-send', 'toast-region',
    'dm-reply-banner', 'dm-reply-banner-text', 'dm-reply-banner-cancel', 'dm-typing-indicator',
  ]) {
    const element = document.getElementById(id);
    if (!element) throw new Error(`Missing direct-message interface element: ${id}`);
    elements[id] = element;
  }
}

function bindEvents() {
  elements['dm-user-search-form'].addEventListener('submit', searchUsers);
  elements['dm-load-older'].addEventListener('click', loadOlder);
  transcriptKeys = attachTranscriptKeys(elements['dm-message-list'], {
    composer: elements['dm-message-input'],
    loadOlder: loadOlder,
    canLoadOlder: () => !elements['dm-load-older'].classList.contains('hidden'),
    selfName: () => state.user?.username ?? null,
  });
  elements['dm-composer'].addEventListener('submit', sendMessage);
  elements['dm-block-toggle'].addEventListener('click', toggleBlock);
  elements['dm-report-person'].addEventListener('click', reportPeer);
  elements['dm-reply-banner-cancel'].addEventListener('click', clearReplyTo);
  elements['dm-message-input'].addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
      if (mentionAutocomplete?.isOpen()) return;
      event.preventDefault();
      elements['dm-composer'].requestSubmit();
    }
  });
  peerTyping = createTypingIndicator(elements['dm-typing-indicator']);
  typingSignal = createTypingSignal(() => apiPost('/api/v1/typing.php', { recipient_user_id: state.selectedUser.id }));
  elements['dm-message-input'].addEventListener('input', () => {
    if (state.shareTyping && state.selectedUser && state.relationship?.messaging_available) typingSignal.input(elements['dm-message-input'].value);
  });
  mentionAutocomplete = attachMentionAutocomplete(elements['dm-message-input'], searchDirectMessageMentions);
  attachNameCompletion(elements['dm-message-input'], () => (state.selectedUser ? [state.selectedUser.username] : []));
  attachEmojiPicker(elements['dm-emoji-button'], elements['dm-message-input']);
}

async function bootstrap() {
  const session = await apiGet('/api/v1/session.php');
  setCsrfToken(session.csrf_token);
  if (!session.user) {
    window.location.assign('/');
    return;
  }

  state.user = session.user;
  state.shareTyping = session.share_typing !== false;
  state.settledResume = session.capabilities?.realtime_settled_resume === true;
  state.mutedEverywhere = session.muted_everywhere ?? null;
  state.privacy = session.privacy?.direct_messages ?? null;
  elements['messages-identity'].textContent = `Signed in as ${state.user.username}`;
  renderPrivacyNotice();
  startRulesGate(state.user);
  startUsernameGate(state.user);
  elements['messages-loading'].classList.add('hidden');
  elements['messages-shell'].classList.remove('hidden');
  startEventStream();
  const requested = requestedConversation();
  await loadConversations(requested);
  if (requested !== null && requested !== state.user.id && state.selectedUser?.id !== requested) {
    await openNewConversation(requested);
  }
}

// The chat links to /messages.php?with=<user id> to open a conversation,
// including one with someone you have not messaged yet.
function requestedConversation() {
  const value = new URLSearchParams(window.location.search).get('with');
  const id = Number(value);
  return Number.isInteger(id) && id > 0 ? id : null;
}

function renderPrivacyNotice() {
  const policy = state.privacy;
  if (!policy) {
    elements['dm-privacy-text'].textContent = 'Direct messages are stored by this server and are not end-to-end encrypted.';
    return;
  }
  const inspection = policy.admin_inspection_enabled
    ? `Administrative inspection is enabled for ${formatRole(policy.admin_inspection_role)} and every inspection is audited.`
    : 'Administrative inspection is disabled.';
  elements['dm-privacy-text'].textContent = `Direct messages are not end-to-end encrypted and are retained ${policy.retention}. ${inspection}`;
}

async function openNewConversation(userId) {
  try {
    const response = await apiGet(`/api/v1/direct-messages/block-status.php?user_id=${encodeURIComponent(userId)}`);
    if (response.user) await selectUser(response.user);
  } catch (error) {
    handleApiFailure(error);
  }
}

async function loadConversations(preferredUserId = null) {
  try {
    const response = await apiGet('/api/v1/direct-messages/conversations.php');
    state.conversations = Array.isArray(response.conversations) ? response.conversations : [];
    renderConversations();

    const selectedId = preferredUserId ?? state.selectedUser?.id ?? null;
    if (selectedId !== null) {
      const existing = state.conversations.find((item) => item.user.id === selectedId);
      if (existing && (!state.selectedUser || state.selectedUser.id !== selectedId)) {
        await selectUser(existing.user);
      }
    } else if (state.conversations[0] && !phoneListShowing()) {
      // On the phone list screen nothing opens until a conversation is chosen.
      await selectUser(state.conversations[0].user);
    }
  } catch (error) {
    handleApiFailure(error);
  }
}

function renderConversations() {
  // Every re-render (load, opening one, read, a new message) keeps the rail's Direct badge current.
  reportDirectCount();
  drawConversationList(elements['dm-conversation-list'], {
    conversations: state.conversations,
    currentUserId: state.selectedUser?.id ?? null,
    pebble,
    onOpen: (user, event, link) => {
      // panes.js: close the tablet overlay, or push the conversation on phones.
      window.dispatchEvent(new CustomEvent('chitchat:conversation-chosen', { detail: { opener: link, user: event.isTrusted } }));
      selectUser(user);
    },
  });
}

/** The rail's Direct badge (shell.js): the unread total across conversations. */
function reportDirectCount() {
  const count = state.conversations.reduce((sum, item) => sum + (Number.isInteger(item?.unread_count) ? item.unread_count : 0), 0);
  window.dispatchEvent(new CustomEvent('chitchat:direct-count', { detail: { count } }));
}

/** A person's pebble: initials on a circle in their identity hue (CCM visual vocabulary). */
function pebble(user) {
  const element = document.createElement('span');
  element.className = 'pebble';
  element.setAttribute('aria-hidden', 'true');
  const hue = identityHueIndex(user.id);
  if (hue !== null) element.dataset.hue = String(hue);
  element.textContent = initials(user.username);
  return element;
}

async function searchUsers(event) {
  event.preventDefault();
  clearError();
  const search = elements['dm-user-search'].value.trim();
  if (search.length < 2) return;
  try {
    const parameters = new URLSearchParams({ search, limit: '20' });
    const response = await apiGet(`/api/v1/direct-messages/users.php?${parameters.toString()}`);
    renderUserResults(Array.isArray(response.users) ? response.users : []);
  } catch (error) {
    handleApiFailure(error);
  }
}

function renderUserResults(users) {
  const container = elements['dm-user-results'];
  container.replaceChildren();
  for (const user of users) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'dm-user-button';
    button.textContent = user.username;
    button.addEventListener('click', async (event) => {
      container.replaceChildren();
      elements['dm-user-search'].value = '';
      window.dispatchEvent(new CustomEvent('chitchat:conversation-chosen', { detail: { opener: elements['dm-user-search'], user: event.isTrusted } }));
      await selectUser(user);
    });
    container.append(button);
  }
  if (users.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'messages-muted';
    empty.textContent = 'No matching users.';
    container.append(empty);
  }
}

async function selectUser(user) {
  const unread = state.conversations.find((conversation) => conversation.user?.id === user.id)?.unread_count ?? 0;
  state.selectedUser = user;
  resetAnnouncements();
  transcriptKeys?.reset();
  peerTyping?.clear();
  delete elements['dm-message-list'].dataset.highlightMessageId;
  state.relationship = null;
  state.messages = [];
  state.messageIds = new Set();
  state.oldestMessageId = null;
  clearReplyTo();
  elements['dm-peer-name'].textContent = user.username;
  const peerPebble = document.getElementById('dm-peer-pebble');
  if (peerPebble) {
    peerPebble.replaceWith(Object.assign(pebble(user), { id: 'dm-peer-pebble' }));
  }
  elements['dm-peer-status'].textContent = 'Loading conversation…';
  elements['dm-composer'].classList.add('hidden');
  elements['dm-block-toggle'].classList.add('hidden');
  elements['dm-report-person'].classList.add('hidden');
  renderConversations();
  renderMessages();
  await Promise.all([
    loadHistory({ replace: true }),
    loadRelationship(user),
  ]);
  if (unread > 0 && state.selectedUser?.id === user.id) {
    announce(`${user.username}. ${unread} new ${unread === 1 ? 'message' : 'messages'}.`);
  }
  await markRead();
}

async function loadRelationship(user = state.selectedUser) {
  if (!user) return;
  try {
    const parameters = new URLSearchParams({ user_id: String(user.id) });
    const response = await apiGet(`/api/v1/direct-messages/block-status.php?${parameters.toString()}`);
    if (state.selectedUser?.id !== user.id) return;
    state.relationship = response.relationship ?? null;
    renderRelationship();
  } catch (error) {
    handleApiFailure(error);
  }
}

function renderRelationship() {
  const user = state.selectedUser;
  const relationship = state.relationship;
  elements['dm-report-person'].classList.toggle('hidden', !user || !relationship);
  if (user) elements['dm-report-person'].setAttribute('aria-label', `Report ${user.username}`);
  if (!user || !relationship) {
    elements['dm-block-toggle'].classList.add('hidden');
    elements['dm-composer'].classList.add('hidden');
    return;
  }

  const blockedByMe = Boolean(relationship.blocked_by_me);
  const available = Boolean(relationship.messaging_available);
  const button = elements['dm-block-toggle'];
  button.classList.remove('hidden');
  button.dataset.blocked = blockedByMe ? 'true' : 'false';
  button.textContent = blockedByMe ? 'Unblock user' : 'Block user';
  button.setAttribute('aria-label', `${blockedByMe ? 'Unblock' : 'Block'} ${user.username}`);

  if (available && state.mutedEverywhere) {
    // Muted everywhere: history stays readable, sending waits for the mute to end.
    const mute = state.mutedEverywhere;
    elements['dm-peer-status'].textContent = `You are muted ${mute.expires_at ? `until ${formatDateTime(mute.expires_at)}` : 'until a moderator lifts it'}, so you cannot send direct messages.${mute.reason ? ` Reason: “${mute.reason}”.` : ''}`;
    elements['dm-composer'].classList.add('hidden');
  } else if (available) {
    elements['dm-peer-status'].textContent = 'Direct conversation';
    elements['dm-composer'].classList.remove('hidden');
    elements['dm-message-input'].disabled = false;
    elements['dm-send'].disabled = false;
  } else {
    elements['dm-peer-status'].textContent = blockedByMe
      ? 'You blocked this user. Existing history remains available.'
      : 'Direct messaging is unavailable. Existing history remains available.';
    elements['dm-composer'].classList.add('hidden');
  }
}

// Report the person on the other side (#159). The profile says what
// moderators would see and whether the server allows it; it decides again
// on submit.
async function reportPeer() {
  const user = state.selectedUser;
  if (!user) return;
  const button = elements['dm-report-person'];
  button.disabled = true;
  clearError();
  try {
    const response = await apiGet(`/api/v1/users/profile.php?user_id=${encodeURIComponent(user.id)}`);
    const profile = response.profile ?? {};
    if (!canReport(profile)) {
      toast('You can’t report this person from here.');
      return;
    }
    openPersonReportDialog({
      userId: user.id,
      username: user.username,
      hasAvatar: Boolean(profile.has_avatar),
      roomId: null,
      ignored: Boolean(profile.ignored),
      blocked: Boolean(state.relationship?.blocked_by_me),
    });
  } catch (error) {
    handleApiFailure(error);
  } finally {
    button.disabled = false;
  }
}

async function toggleBlock() {
  const user = state.selectedUser;
  const relationship = state.relationship;
  if (!user || !relationship) return;
  const button = elements['dm-block-toggle'];
  button.disabled = true;
  clearError();
  try {
    const path = relationship.blocked_by_me
      ? '/api/v1/direct-messages/unblock.php'
      : '/api/v1/direct-messages/block.php';
    const response = await apiPost(path, { user_id: user.id });
    if (state.selectedUser?.id !== user.id) return;
    state.relationship = response.relationship ?? null;
    renderRelationship();
    toast(state.relationship?.blocked_by_me ? `${user.username} blocked.` : `${user.username} unblocked.`);
  } catch (error) {
    handleApiFailure(error);
  } finally {
    button.disabled = false;
  }
}

async function loadHistory({ replace = false, beforeId = null } = {}) {
  const user = state.selectedUser;
  if (!user) return;
  elements['dm-load-older'].disabled = true;
  const older = beforeId !== null;
  if (older) {
    elements['dm-message-list'].setAttribute('aria-busy', 'true');
    announce('Loading older messages…');
  }
  try {
    const parameters = new URLSearchParams({ user_id: String(user.id), limit: '100' });
    if (beforeId !== null) parameters.set('before_id', String(beforeId));
    const response = await apiGet(`/api/v1/direct-messages/history.php?${parameters.toString()}`);
    if (state.selectedUser?.id !== user.id) return;
    const incoming = Array.isArray(response.messages) ? response.messages : [];
    if (replace) {
      state.messages = [];
      state.messageIds = new Set();
    }
    const fresh = incoming.filter((message) => !state.messageIds.has(message.id));
    for (const message of fresh) state.messageIds.add(message.id);
    state.messages = beforeId === null ? [...state.messages, ...fresh] : [...fresh, ...state.messages];
    state.messages.sort((left, right) => left.id - right.id);
    state.oldestMessageId = state.messages[0]?.id ?? null;
    elements['dm-load-older'].classList.toggle('hidden', incoming.length < 100);
    renderMessages({ scrollToEnd: replace && beforeId === null, prepended: beforeId !== null });
    if (older) {
      announce(fresh.length > 0
        ? `${fresh.length} older ${fresh.length === 1 ? 'message' : 'messages'} loaded.`
        : 'No older messages.');
    }
  } catch (error) {
    handleApiFailure(error);
  } finally {
    elements['dm-load-older'].disabled = false;
    if (older) elements['dm-message-list'].setAttribute('aria-busy', 'false');
  }
}

// One older-history request at a time per conversation (see app.js).
let olderLoad = null;
function loadOlder() {
  const userId = state.selectedUser?.id;
  if (olderLoad && olderLoad.userId === userId) return olderLoad.promise;
  if (state.oldestMessageId === null) return Promise.resolve();
  const entry = { userId, promise: null };
  entry.promise = loadHistory({ beforeId: state.oldestMessageId }).finally(() => {
    if (olderLoad === entry) olderLoad = null;
  });
  olderLoad = entry;
  return entry.promise;
}

function renderMessages({ scrollToEnd = false, prepended = false } = {}) {
  const list = elements['dm-message-list'];
  // The list scrolls on its own and rebuilding it resets scrollTop, so keep the
  // reader's place: same offset for in-place updates, same messages in view
  // when older history is prepended above them.
  const previousTop = list.scrollTop;
  const previousHeight = list.scrollHeight;
  const focus = focusedItem(list);
  if (state.messages.length === 0) {
    reconcile(list, []);
    elements['dm-empty-state'].classList.remove('hidden');
    elements['dm-empty-state'].textContent = state.selectedUser ? 'No messages yet.' : 'No conversation selected.';
    return;
  }
  elements['dm-empty-state'].classList.add('hidden');
  // Only what changed is touched (transcript.js), the same list as in rooms.
  const items = elements['dm-load-older'].classList.contains('hidden')
    ? []
    : [controlItem('load-older', elements['dm-load-older'])];
  const runs = runPositions(state.messages, {
    id: (message) => message.id,
    sender: (message) => message.sender?.id ?? message.sender?.username,
    date: (message) => message.created_at,
  });
  items.push(...withDays(state.messages, (message) => message.created_at, (message) => messageItem(message, runs.get(message.id) ?? 'single')));
  reconcile(list, items);
  for (const article of list.querySelectorAll('article')) {
    article.classList.toggle('search-result-target', article.dataset.messageId === list.dataset.highlightMessageId);
  }
  restoreFocus(list, focus);
  transcriptKeys?.sync();
  if (scrollToEnd) list.scrollTop = list.scrollHeight;
  else if (prepended) list.scrollTop = previousTop + (list.scrollHeight - previousHeight);
  else list.scrollTop = previousTop;
}

function messageItem(message, run = 'single') {
  const { reactions, ...rest } = message;
  return {
    key: `m:${message.id}`,
    signature: signatureOf([rest, state.user?.id ?? null, formatDateTime(message.created_at), run]),
    reactions: signatureOf(reactions ?? []),
    build: () => buildMessage(message, run),
    patchReactions: (article) => {
      article.querySelector('.reaction-bar')?.replaceWith(reactionBarFor(message));
    },
  };
}

// Re-rendering keeps unchanged nodes, so focus usually stays; a rebuilt
// message gets it back by id and position.
function focusedItem(list) {
  const active = document.activeElement;
  if (!(active instanceof HTMLElement) || !list.contains(active)) return null;
  const article = active.closest('article[data-message-id]');
  if (!article) return null;
  const focusable = [...article.querySelectorAll('button, a[href], [tabindex]')];
  return { id: article.dataset.messageId, index: focusable.indexOf(active), self: active === article };
}

function restoreFocus(list, focus) {
  if (!focus || list.contains(document.activeElement)) return;
  const article = list.querySelector(`article[data-message-id="${focus.id}"]`);
  if (!article) return;
  const target = focus.self ? article : [...article.querySelectorAll('button, a[href], [tabindex]')][focus.index];
  target?.focus({ preventScroll: true });
}

function buildMessage(message, run = 'single') {
  const article = document.createElement('article');
  article.className = 'dm-message';
  article.classList.toggle('outgoing', Boolean(message.outgoing));
  article.dataset.messageId = String(message.id);
  article.dataset.run = run;
  article.dataset.author = message.sender?.username ?? 'Someone';
  // Re-apply a reply/search highlight, since renderMessages() rebuilds every node.
  article.classList.toggle(
    'search-result-target',
    elements['dm-message-list'].dataset.highlightMessageId === article.dataset.messageId,
  );

  const bubble = document.createElement('div');
  bubble.className = 'message-bubble';
  const preview = buildReplyPreview(message.reply_to);
  if (preview) {
    preview.addEventListener('click', () => focusReplyTarget(message.reply_to));
    bubble.append(preview);
  }

  const body = document.createElement('div');
  body.className = 'dm-message-body';
  renderMessageBody(body, message.body ?? '', message.mentions);

  const meta = document.createElement('span');
  meta.className = 'dm-message-meta';
  const read = message.outgoing && message.read_at ? ' · read' : '';
  // The day heading carries the date and the transcript reads the sender; the bubble shows the time.
  meta.textContent = `${formatDateTime(message.created_at, { dateStyle: null, timeStyle: 'short' })}${read}`;
  meta.title = formatDateTime(message.created_at);

  const actions = document.createElement('span');
  actions.className = 'dm-message-actions';
  const replyButton = document.createElement('button');
  replyButton.type = 'button';
  replyButton.className = 'message-reply-button';
  replyButton.textContent = 'Reply';
  replyButton.addEventListener('click', () => setReplyTo(message));
  actions.append(replyButton);

  const header = document.createElement('div');
  header.className = 'message-header';
  header.append(reactButton(), actions, moreButton());
  bubble.append(body, meta);
  article.append(header, bubble);
  article.append(reactionBarFor(message));
  return article;
}

function reactionBarFor(message) {
  return buildReactionBar(message.reactions, state.user?.id, (emoji, reactedByMe) => {
    toggleReaction(message.id, emoji, reactedByMe);
  });
}

async function toggleReaction(messageId, emoji, reactedByMe) {
  try {
    const endpoint = reactedByMe ? '/api/v1/direct-messages/unreact.php' : '/api/v1/direct-messages/react.php';
    const response = await apiPost(endpoint, { message_id: messageId, emoji });
    updateMessageReactions(messageId, response.reactions);
  } catch (error) {
    handleApiFailure(error);
  }
}

function updateMessageReactions(messageId, reactions) {
  const message = state.messages.find((candidate) => candidate.id === messageId);
  if (!message) {
    return;
  }
  message.reactions = Array.isArray(reactions) ? reactions : [];
  renderMessages();
}

function searchDirectMessageMentions(prefix) {
  const peer = state.selectedUser;
  if (!peer || !peer.username.toLowerCase().startsWith(prefix.toLowerCase())) return Promise.resolve([]);
  return Promise.resolve([{ id: peer.id, username: peer.username }]);
}

function setReplyTo(message) {
  state.replyTo = {
    id: message.id,
    username: message.sender.username,
    body: message.body,
    deleted: false,
  };
  renderReplyBanner();
  elements['dm-message-input'].focus();
}

function clearReplyTo() {
  if (state.replyTo === null) return;
  state.replyTo = null;
  renderReplyBanner();
}

function renderReplyBanner() {
  const reply = state.replyTo;
  elements['dm-reply-banner'].classList.toggle('hidden', reply === null);
  if (!reply) {
    delete elements['dm-reply-banner'].dataset.replyToId;
    return;
  }
  elements['dm-reply-banner'].dataset.replyToId = String(reply.id);
  const excerpt = truncateForBanner(reply.body ?? '');
  elements['dm-reply-banner-text'].textContent = `Replying to ${reply.username}: “${excerpt}”`;
}

function truncateForBanner(text) {
  const collapsed = text.replace(/\s+/gu, ' ').trim();
  return collapsed.length > 80 ? `${collapsed.slice(0, 79).trimEnd()}…` : collapsed;
}

function focusReplyTarget(replyTo) {
  if (!replyTo?.available) return;
  const list = elements['dm-message-list'];
  const target = list.querySelector(`article[data-message-id="${replyTo.message_id}"]`);
  if (!(target instanceof HTMLElement)) return;
  list.querySelectorAll('.search-result-target').forEach((node) => node.classList.remove('search-result-target'));
  list.dataset.highlightMessageId = target.dataset.messageId;
  target.classList.add('search-result-target');
  target.scrollIntoView({ block: 'center', behavior: 'auto' });
  transcriptKeys?.focus(target);
  announce(`Original message from ${replyTo.message?.sender?.username ?? 'someone'}`);
}

async function sendMessage(event) {
  event.preventDefault();
  const user = state.selectedUser;
  const body = elements['dm-message-input'].value.trim();
  if (!user || !body || !state.relationship?.messaging_available) return;
  elements['dm-send'].disabled = true;
  try {
    const payload = { recipient_user_id: user.id, body };
    if (state.replyTo) payload.reply_to_message_id = state.replyTo.id;
    const response = await apiPost('/api/v1/direct-messages/send.php', payload);
    typingSignal?.reset();
    elements['dm-message-input'].value = '';
    clearReplyTo();
    appendMessage(response.message, true);
    await loadConversations(user.id);
  } catch (error) {
    if (error instanceof ApiError && error.code === 'direct_message_unavailable') {
      await loadRelationship(user);
    }
    handleApiFailure(error, MESSAGE_NOT_SENT);
  } finally {
    elements['dm-send'].disabled = false;
    if (state.relationship?.messaging_available) elements['dm-message-input'].focus();
  }
}

function appendMessage(message, scrollToEnd = false) {
  if (!message || state.messageIds.has(message.id)) return false;
  state.messageIds.add(message.id);
  state.messages.push(message);
  state.messages.sort((left, right) => left.id - right.id);
  state.oldestMessageId = state.messages[0]?.id ?? null;
  renderMessages({ scrollToEnd });
  return true;
}

async function markRead() {
  if (!state.selectedUser) return;
  try {
    await apiPost('/api/v1/direct-messages/read.php', { user_id: state.selectedUser.id });
    await refreshConversationsOnly();
  } catch (error) {
    if (!(error instanceof ApiError && error.status === 401)) logError(error);
  }
}

async function refreshConversationsOnly() {
  const response = await apiGet('/api/v1/direct-messages/conversations.php');
  state.conversations = Array.isArray(response.conversations) ? response.conversations : [];
  renderConversations();
}

// The stream stays open across reconnects and upgrades (#301): realtime-stream.js recreates
// it when the browser gives up, from the last position, and drops resent events (#286).
// The status line follows it (#309, S7 ladder); a long outage is also said once.
let statusLine = null;
function startEventStream() {
  state.stream?.stop();
  statusLine ??= createConnectionStatus(document.getElementById('dm-connection-status'));
  state.stream = keepEventStream({
    settled: state.settledResume,
    attach: attachStreamListeners,
    probe: sessionProbe(apiGet),
    onStatus: (status) => statusLine.set(status),
    onSignedOut: () => window.location.assign('/'),
    onLongOutage: () => announce(LONG_OUTAGE_NOTICE),
  });
}

function attachStreamListeners(source) {
  source.addEventListener('direct_message', (event) => {
    const envelope = parseEvent(event);
    const message = envelope?.payload?.message;
    if (!message || !state.user) return;
    const peer = message.sender.id === state.user.id ? message.recipient : message.sender;
    if (!message.outgoing) peerTyping?.remove(peer.id);
    // No sound for the conversation you are looking at right now.
    const watching = state.selectedUser?.id === peer.id && document.visibilityState === 'visible';
    if (!message.outgoing && !watching) {
      alertUser('dm', `Message from ${peer.username}`);
    }
    if (state.selectedUser?.id === peer.id && appendMessage(message, true)) {
      // The transcript is not a live region; the announcer speaks new messages once.
      if (message.outgoing) markKnown([`dm:${message.id}`]);
      else announceIncoming({ key: `dm:${message.id}`, sender: peer.username, body: message.body, direct: true });
    }
    if (state.selectedUser?.id === peer.id) {
      if (!message.outgoing) markRead().catch(logError);
    } else if (!message.outgoing) {
      toast(`New message from ${peer.username}.`);
    }
    refreshConversationsOnly().catch(handleApiFailure);
  });

  source.addEventListener('message_reaction_changed', (event) => {
    const envelope = parseEvent(event);
    const payload = envelope?.payload;
    if (payload?.message_kind === 'direct') {
      updateMessageReactions(payload.message_id, payload.reactions);
    }
  });
  source.addEventListener('typing', (event) => {
    const payload = parseEvent(event)?.payload;
    // Direct conversations only; room typing belongs to the chat page.
    if (payload?.room_id === null && payload.user?.id === state.selectedUser?.id) peerTyping?.add(payload.user);
  });
  // A name changed (#164): the open conversation and the inbox follow; your own refreshes the session.
  source.addEventListener('user_renamed', (event) => {
    const user = renamedUser(parseEvent(event)?.payload);
    if (!user) return;
    if (user.id === state.user?.id) {
      apiGet('/api/v1/session.php').then((session) => {
        if (session.user?.id !== state.user?.id) return;
        state.user = session.user;
        startUsernameGate(session.user);
      }).catch(handleApiFailure);
    }
    if (state.selectedUser?.id === user.id) {
      state.selectedUser = { ...state.selectedUser, username: user.username };
      elements['dm-peer-name'].textContent = user.username;
      document.getElementById('dm-peer-pebble')?.replaceWith(Object.assign(pebble(state.selectedUser), { id: 'dm-peer-pebble' }));
    }
    if (renameInMessages(state.messages, user)) renderMessages();
    peerTyping?.remove(user.id);
    refreshConversationsOnly().catch(handleApiFailure);
  });
  source.addEventListener('forced_logout', (event) => {
    // Signed out from another device (#205): the sign-in screen says so.
    if (parseEvent(event)?.reason === 'session_revoked') rememberSignedOutNotice(REVOKED_NOTICE);
    window.location.assign('/');
  });
}

function parseEvent(event) {
  try { return JSON.parse(event.data); } catch { return null; }
}

function formatRole(role) {
  return role === 'super_admin' ? 'Super-Administrators' : 'Administrators';
}


function toast(message, kind = 'info') {
  const node = document.createElement('div');
  node.className = `toast ${kind}`;
  node.textContent = message;
  elements['toast-region'].append(node);
  window.setTimeout(() => node.remove(), 5000);
}

function clearError() { elements['messages-error'].textContent = ''; }
function handleApiFailure(error, then = '') {
  // A request the page aborted on leaving shows nothing (#270).
  if (isPageLeft(error)) return;
  if (error instanceof ApiError && error.status === 401) {
    window.location.assign('/');
    return;
  }
  const reason = errorText(error, 'The request failed.');
  // `then`: what happened to what was being sent, e.g. "Your message wasn't sent."
  elements['messages-error'].textContent = then ? failedSending(reason, then) : reason;
}
function handleFatal(error) {
  // Nothing to show for a request the page aborted on leaving (#270).
  if (isPageLeft(error)) return;
  elements['messages-loading'].textContent = error instanceof Error ? error.message : 'Unable to load direct messages.';
}

// "Block direct messages" after a report updates the open conversation.
window.addEventListener('chitchat:block-changed', (event) => {
  if (state.selectedUser?.id === event.detail?.userId) void loadRelationship(state.selectedUser);
});
