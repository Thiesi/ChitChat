/*
 * The small formatting language of chat messages, parsed into plain data
 * that message-content.js turns into DOM nodes. Nothing here produces HTML,
 * so message text can never become markup.
 *
 * Blocks: ``` fenced code blocks ``` and "> " quoted lines; everything else
 * is text. Inline: `code`, *bold* (also **bold**), _italic_ and http(s)
 * links. Markers only count when they hug a word (`*so*`, not `2 * 3 * 4`
 * or snake_case_names), and not right after a backslash, so ordinary text
 * stays as typed.
 */

const URL_PATTERN = /https?:\/\/[^\s<>"'`]+/iuy;
const MENTION_PATTERN = /@[A-Za-z0-9][A-Za-z0-9_.-]{2,31}/uy;
const TRAILING_PUNCTUATION = /[.,;:!?'"]+$/u;
const WORD_CHARACTER = /[\p{L}\p{N}]/u;

/**
 * @typedef {{ type: 'text', text: string }
 *   | { type: 'code', text: string }
 *   | { type: 'link', href: string, text: string }
 *   | { type: 'strong' | 'em', children: Inline[] }} Inline
 * @typedef {{ type: 'paragraph' | 'quote', children: Inline[] }
 *   | { type: 'codeblock', text: string }} Block
 */

/** @returns {Block[]} */
export function parseMessage(text) {
  const blocks = [];
  const lines = String(text).split('\n');
  let paragraph = [];
  let quote = [];
  const flush = () => {
    if (paragraph.length > 0) blocks.push({ type: 'paragraph', children: parseInline(paragraph.join('\n')) });
    if (quote.length > 0) blocks.push({ type: 'quote', children: parseInline(quote.join('\n')) });
    paragraph = [];
    quote = [];
  };

  for (let index = 0; index < lines.length; index += 1) {
    const line = lines[index];
    if (line.trimEnd().startsWith('```')) {
      const end = lines.findIndex((candidate, at) => at > index && candidate.trim() === '```');
      if (end !== -1) {
        flush();
        // Text after the opening fence (a language name, say) is kept as code.
        const first = line.trimEnd().slice(3);
        const body = lines.slice(index + 1, end);
        blocks.push({ type: 'codeblock', text: (first.trim() === '' ? body : [first, ...body]).join('\n') });
        index = end;
        continue;
      }
    }
    const quoted = /^> ?(.*)$/u.exec(line);
    if (quoted) {
      if (paragraph.length > 0) flush();
      quote.push(quoted[1]);
    } else {
      if (quote.length > 0) flush();
      paragraph.push(line);
    }
  }
  flush();
  return blocks;
}

/**
 * Inline formatting only, for places that cannot hold blocks (the action of
 * a /me message).
 *
 * @returns {Inline[]}
 */
export function parseInline(text) {
  const nodes = [];
  let plain = '';
  const pushPlain = () => {
    if (plain !== '') nodes.push({ type: 'text', text: plain });
    plain = '';
  };

  let index = 0;
  while (index < text.length) {
    const token = readToken(text, index);
    if (token === null) {
      plain += text[index];
      index += 1;
      continue;
    }
    if (token.node) {
      pushPlain();
      nodes.push(token.node);
    } else {
      plain += token.text;
    }
    index = token.end;
  }
  pushPlain();
  return nodes;
}

/** Returns an http(s) URL in canonical form, or null for anything else. */
export function safeLinkTarget(candidate) {
  try {
    const url = new URL(candidate);
    return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null;
  } catch {
    return null;
  }
}

function readToken(text, index) {
  const character = text[index];
  // A marker right after a backslash is meant literally, as in ¯\_(ツ)_/¯.
  if (index > 0 && text[index - 1] === '\\' && '*_`'.includes(character)) return null;
  if (character === '`') return readCode(text, index);
  if (character === 'h' || character === 'H') return readLink(text, index);
  // A mention is one word, even with underscores or dots inside, so it is
  // passed through whole rather than read as the start of italics.
  if (character === '@') return readMention(text, index);
  if (character === '*') return readEmphasis(text, index, text.startsWith('**', index) ? '**' : '*', 'strong');
  if (character === '_') return readEmphasis(text, index, '_', 'em');
  return null;
}

function readCode(text, index) {
  const end = text.indexOf('`', index + 1);
  if (end === -1 || end === index + 1) return null;
  const content = text.slice(index + 1, end);
  if (content.includes('\n')) return null;
  return { node: { type: 'code', text: content }, end: end + 1 };
}

function readLink(text, index) {
  if (index > 0 && WORD_CHARACTER.test(text[index - 1])) return null;
  URL_PATTERN.lastIndex = index;
  const match = URL_PATTERN.exec(text);
  if (!match) return null;
  let raw = match[0].replace(TRAILING_PUNCTUATION, '');
  // A closing bracket belongs to the link only if the link opened one.
  while (raw.endsWith(')') && count(raw, '(') < count(raw, ')')) {
    raw = raw.slice(0, -1).replace(TRAILING_PUNCTUATION, '');
  }
  const href = safeLinkTarget(raw);
  if (href === null || raw.length <= 'https://'.length) return null;
  return { node: { type: 'link', href, text: raw }, end: index + raw.length };
}

function readMention(text, index) {
  MENTION_PATTERN.lastIndex = index;
  const match = MENTION_PATTERN.exec(text);
  return match ? { text: match[0], end: index + match[0].length } : null;
}

function readEmphasis(text, index, marker, type) {
  const start = index + marker.length;
  // Opening: not inside a word, and followed by the word it emphasises.
  if (index > 0 && WORD_CHARACTER.test(text[index - 1])) return null;
  if (start >= text.length || /\s/u.test(text[start]) || text[start] === marker[0]) return null;

  let search = start;
  while (search < text.length) {
    const end = text.indexOf(marker, search);
    if (end === -1) return null;
    const content = text.slice(start, end);
    if (content.includes('\n')) return null;
    const after = text[end + marker.length];
    // Closing: right after a word, and not in the middle of one.
    if (!/\s/u.test(text[end - 1]) && (after === undefined || !WORD_CHARACTER.test(after)) && text[end - 1] !== marker[0]) {
      return { node: { type, children: parseInline(content) }, end: end + marker.length };
    }
    search = end + 1;
  }
  return null;
}

function count(text, character) {
  return text.split(character).length - 1;
}
