// The mention grammar shared by the formatter and DOM renderer (#218).
// Keep in step with MentionParser and tests/fixtures/mention-punctuation.json.
const WORD = '(?<![A-Za-z0-9_.-])@([A-Za-z0-9][A-Za-z0-9_.-]{2,})';
const SHAPE = /^[A-Za-z0-9][A-Za-z0-9_.-]{2,31}$/u;

/** Longest first; strip only one trailing dot, hyphen or underscore per step. */
export function mentionCandidates(raw) {
  const candidates = [];
  let token = raw.toLowerCase();
  while (token.length >= 3) {
    if (SHAPE.test(token)) candidates.push(token);
    if (!/[.\-_]$/u.test(token)) break;
    token = token.slice(0, -1);
  }
  return candidates;
}

/** Resolve only against names authorized for this context; broadcast is opt-in. */
export function resolveMention(raw, isKnown, broadcast = false) {
  return mentionCandidates(raw).find(token =>
    (broadcast && (token === 'room' || token === 'here')) || isKnown(token)) ?? null;
}

/** Complete raw words, never a truncated 32-character prefix of a longer name. */
export function* mentionTokens(text) {
  for (const match of text.matchAll(new RegExp(WORD, 'gu'))) {
    if (mentionCandidates(match[1]).length > 0) {
      yield { index: match.index, raw: match[1], text: match[0], end: match.index + match[0].length };
    }
  }
}

/** Lexical only: protect full usernames from inline underscore formatting. */
export function mentionAt(text, index) {
  const pattern = new RegExp(WORD, 'uy');
  pattern.lastIndex = index;
  const match = pattern.exec(text);
  return match && mentionCandidates(match[1]).length > 0
    ? { text: match[0], end: index + match[0].length } : null;
}
