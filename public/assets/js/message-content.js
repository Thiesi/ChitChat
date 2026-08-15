const MENTION_TOKEN = /@([A-Za-z0-9][A-Za-z0-9_.-]{2,31})/gu;
const REACTION_EMOJI = ['👍', '❤️', '😂', '😮', '😢', '🎉'];
let reactionBarInstanceCount = 0;

/**
 * Renders message body text into `container`, wrapping only tokens the
 * server actually resolved and authorized (present in `mentions`) in a
 * `.mention` span. Any other `@word`-shaped text is left as plain text,
 * since ChitChat never highlights an unauthorized or unresolved token as
 * if it were a real mention.
 *
 * @param {HTMLElement} container
 * @param {string} text
 * @param {Array<{ user_id: number, username: string, broadcast?: boolean }>} mentions
 */
export function renderMessageBody(container, text, mentions) {
  container.replaceChildren();
  const usernames = new Set(
    (Array.isArray(mentions) ? mentions : [])
      .map((mention) => (typeof mention?.username === 'string' ? mention.username.toLowerCase() : null))
      .filter((username) => username !== null),
  );
  const hasBroadcast = (Array.isArray(mentions) ? mentions : []).some((mention) => mention?.broadcast === true);

  let cursor = 0;
  MENTION_TOKEN.lastIndex = 0;
  let match = MENTION_TOKEN.exec(text);
  while (match !== null) {
    const token = match[1].toLowerCase();
    const isBroadcastToken = hasBroadcast && (token === 'room' || token === 'here');
    if (usernames.has(token) || isBroadcastToken) {
      if (match.index > cursor) {
        container.append(document.createTextNode(text.slice(cursor, match.index)));
      }
      const span = document.createElement('span');
      span.className = isBroadcastToken ? 'mention mention-broadcast' : 'mention';
      span.textContent = match[0];
      container.append(span);
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
