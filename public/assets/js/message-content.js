import { parseInline, parseMessage } from './message-format.js';
import { nameButton } from './name-menu.js';

const MENTION_TOKEN = /@([A-Za-z0-9][A-Za-z0-9_.-]{2,31})/gu;
const REACTION_EMOJI = ['👍', '❤️', '😂', '😮', '😢', '🎉'];
let reactionBarInstanceCount = 0;

/**
 * Renders message body text into `container`: the formatting subset of
 * message-format.js (code, bold, italic, links, quotes, code blocks), built
 * as DOM nodes so text never becomes markup. Only tokens the server actually
 * resolved and authorized (present in `mentions`) are wrapped in a
 * `.mention`; any other `@word`-shaped text is left as plain text, since
 * ChitChat never highlights an unauthorized or unresolved token as if it
 * were a real mention.
 *
 * @param {HTMLElement} container
 * @param {string} text
 * @param {Array<{ user_id: number, username: string, broadcast?: boolean }>} mentions
 * @param {{ nameButtons?: boolean, inline?: boolean }} [options] nameButtons
 *   renders personal mentions as buttons that open the name menu; inline
 *   skips quotes and code blocks, for containers that cannot hold blocks.
 */
export function renderMessageBody(container, text, mentions, { nameButtons = false, inline = false } = {}) {
  container.replaceChildren();
  const list = Array.isArray(mentions) ? mentions : [];
  const context = {
    nameButtons,
    mentionedIds: new Map(
      list
        .filter((mention) => typeof mention?.username === 'string')
        .map((mention) => [mention.username.toLowerCase(), mention.user_id]),
    ),
    hasBroadcast: list.some((mention) => mention?.broadcast === true),
  };
  if (inline) {
    appendInline(container, parseInline(text), context);
    return;
  }
  const blocks = parseMessage(text);
  // The common case, one plain paragraph, stays a run of text in the body.
  if (blocks.length === 1 && blocks[0].type === 'paragraph') {
    appendInline(container, blocks[0].children, context);
    return;
  }
  for (const block of blocks) {
    if (block.type === 'codeblock') {
      const pre = document.createElement('pre');
      pre.className = 'message-codeblock';
      const code = document.createElement('code');
      code.textContent = block.text;
      pre.append(code);
      container.append(pre);
    } else {
      const node = document.createElement(block.type === 'quote' ? 'blockquote' : 'div');
      node.className = block.type === 'quote' ? 'message-quote' : 'message-paragraph';
      appendInline(node, block.children, context);
      container.append(node);
    }
  }
}

function appendInline(container, nodes, context) {
  for (const node of nodes) {
    if (node.type === 'text') {
      appendTextWithMentions(container, node.text, context);
    } else if (node.type === 'code') {
      const code = document.createElement('code');
      code.className = 'message-code';
      code.textContent = node.text;
      container.append(code);
    } else if (node.type === 'link') {
      const link = document.createElement('a');
      link.className = 'message-link';
      link.href = node.href;
      link.target = '_blank';
      link.rel = 'noopener noreferrer nofollow';
      link.textContent = node.text;
      container.append(link);
    } else {
      const element = document.createElement(node.type === 'strong' ? 'strong' : 'em');
      appendInline(element, node.children, context);
      container.append(element);
    }
  }
}

function appendTextWithMentions(container, text, { nameButtons, mentionedIds, hasBroadcast }) {
  let cursor = 0;
  MENTION_TOKEN.lastIndex = 0;
  let match = MENTION_TOKEN.exec(text);
  while (match !== null) {
    const token = match[1].toLowerCase();
    const isBroadcastToken = hasBroadcast && (token === 'room' || token === 'here');
    if (mentionedIds.has(token) || isBroadcastToken) {
      if (match.index > cursor) {
        container.append(document.createTextNode(text.slice(cursor, match.index)));
      }
      const userId = mentionedIds.get(token);
      if (nameButtons && !isBroadcastToken && Number.isInteger(userId)) {
        const button = nameButton({ id: userId, username: match[1] }, 'mention');
        button.textContent = match[0];
        container.append(button);
      } else {
        const span = document.createElement('span');
        span.className = isBroadcastToken ? 'mention mention-broadcast' : 'mention';
        span.textContent = match[0];
        container.append(span);
      }
      cursor = match.index + match[0].length;
    }
    match = MENTION_TOKEN.exec(text);
  }
  if (cursor < text.length) {
    container.append(document.createTextNode(text.slice(cursor)));
  }
}

/**
 * Builds the quoted reply-preview block shown above a reply's own body,
 * or null when the message isn't a reply.
 *
 * @param {{ available: boolean, message: null | { sender: null | { username: string }, body: string | null, deleted: boolean } }} replyTo
 * @returns {HTMLElement | null}
 */
export function buildReplyPreview(replyTo) {
  if (!replyTo) return null;

  if (!replyTo.available || !replyTo.message) {
    const unavailable = document.createElement('p');
    unavailable.className = 'reply-preview reply-preview-unavailable';
    unavailable.textContent = 'Original message no longer available.';
    return unavailable;
  }

  const preview = document.createElement('button');
  preview.type = 'button';
  preview.className = 'reply-preview';

  const author = document.createElement('span');
  author.className = 'reply-preview-author';
  author.textContent = replyTo.message.sender?.username ?? 'Someone';

  const excerpt = document.createElement('span');
  excerpt.className = 'reply-preview-excerpt';
  excerpt.textContent = replyTo.message.deleted
    ? 'Message deleted.'
    : truncate(replyTo.message.body ?? '', 160);

  preview.append(author, excerpt);
  return preview;
}

/**
 * Builds the reaction bar shown under a message: pills for emoji someone
 * has already used (click toggles the viewer's own reaction), plus an
 * "Add reaction" disclosure button revealing the full vocabulary. Whether a
 * reactor's own reaction should render as active is always derived here
 * from each emoji's reactor list compared against `viewerUserId`, never
 * trusted blindly from the server's `reacted_by_me` — a room broadcast
 * carries one shared payload for every recipient, so only the reactor list
 * itself is guaranteed correct for every viewer.
 *
 * @param {Array<{ emoji: string, users: Array<{ id: number, username: string }> }>} reactions
 * @param {number | null | undefined} viewerUserId
 * @param {(emoji: string, reactedByMe: boolean) => void} onToggle
 * @returns {HTMLElement}
 */
export function buildReactionBar(reactions, viewerUserId, onToggle) {
  const list = Array.isArray(reactions) ? reactions : [];
  const byEmoji = new Map(list.map((entry) => [entry.emoji, entry]));
  const instanceId = `reaction-picker-${(reactionBarInstanceCount += 1)}`;

  const bar = document.createElement('div');
  bar.className = 'reaction-bar';

  for (const entry of list) {
    const users = Array.isArray(entry?.users) ? entry.users : [];
    if (users.length === 0) continue;
    bar.append(buildReactionButton(entry.emoji, users, viewerUserId, onToggle));
  }

  const picker = document.createElement('div');
  picker.className = 'reaction-picker';
  picker.id = instanceId;
  picker.hidden = true;
  for (const emoji of REACTION_EMOJI) {
    const users = byEmoji.get(emoji)?.users ?? [];
    const option = document.createElement('button');
    option.type = 'button';
    option.className = 'reaction-picker-option';
    option.textContent = emoji;
    option.setAttribute('aria-label', `React with ${emoji}`);
    option.addEventListener('click', () => {
      picker.hidden = true;
      addButton.setAttribute('aria-expanded', 'false');
      const reactedByMe = users.some((user) => user?.id === viewerUserId);
      onToggle(emoji, reactedByMe);
    });
    picker.append(option);
  }

  const addButton = document.createElement('button');
  addButton.type = 'button';
  addButton.className = 'reaction-add-button';
  addButton.textContent = '+';
  addButton.setAttribute('aria-label', 'Add reaction');
  addButton.setAttribute('aria-haspopup', 'true');
  addButton.setAttribute('aria-expanded', 'false');
  addButton.setAttribute('aria-controls', instanceId);
  addButton.addEventListener('click', () => {
    const willShow = picker.hidden;
    picker.hidden = !willShow;
    addButton.setAttribute('aria-expanded', willShow ? 'true' : 'false');
  });

  bar.append(addButton, picker);
  return bar;
}

function buildReactionButton(emoji, users, viewerUserId, onToggle) {
  const reactedByMe = users.some((user) => user?.id === viewerUserId);
  const names = users.map((user) => user.username).join(', ');

  const button = document.createElement('button');
  button.type = 'button';
  button.className = reactedByMe ? 'reaction-button reaction-button-active' : 'reaction-button';
  button.setAttribute('aria-pressed', reactedByMe ? 'true' : 'false');
  button.setAttribute('aria-label', `${emoji} reaction, ${users.length} ${users.length === 1 ? 'person' : 'people'}: ${names}`);
  button.title = names;

  const glyph = document.createElement('span');
  glyph.setAttribute('aria-hidden', 'true');
  glyph.textContent = emoji;
  const count = document.createElement('span');
  count.className = 'reaction-button-count';
  count.setAttribute('aria-hidden', 'true');
  count.textContent = String(users.length);
  button.append(glyph, count);

  button.addEventListener('click', () => onToggle(emoji, reactedByMe));
  return button;
}

function truncate(text, maxLength) {
  const collapsed = text.replace(/\s+/gu, ' ').trim();
  return collapsed.length > maxLength ? `${collapsed.slice(0, maxLength - 1).trimEnd()}…` : collapsed;
}
