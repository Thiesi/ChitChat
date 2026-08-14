const MENTION_TOKEN = /@([A-Za-z0-9][A-Za-z0-9_.-]{2,31})/gu;

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

function truncate(text, maxLength) {
  const collapsed = text.replace(/\s+/gu, ' ').trim();
  return collapsed.length > maxLength ? `${collapsed.slice(0, maxLength - 1).trimEnd()}…` : collapsed;
}
