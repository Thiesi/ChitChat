// Highlighting search matches in result excerpts (S4d, #170). The server
// matches with websearch_to_tsquery('simple', …) (MessageSearchService):
// whole words, case-insensitive, no stemming; "quoted phrases" match their
// words; OR is an operator; a leading - excludes a word. The highlight
// mirrors that: <mark> on whole-word hits of the included words, nothing
// else. Marks are built from text nodes and elements, never from HTML.
//
// No DOM access at import time, so the pure part can be unit-tested.

// A "word" as the simple parser sees it, roughly: letters and digits in any script.
const WORD = /[\p{L}\p{N}]+/gu;

/** The words a query asks for: lowercase, unique, without OR and excluded (-) words. */
export function queryTerms(query) {
  const terms = new Set();
  const source = String(query ?? '');
  // Tokens: a "phrase" or a bare run of non-space characters.
  for (const match of source.matchAll(/(-?)"([^"]*)"|(\S+)/gu)) {
    const excluded = match[1] === '-' || (match[3] ?? '').startsWith('-');
    const text = match[2] ?? match[3] ?? '';
    if (excluded) continue;
    if (match[3] !== undefined && text.toLowerCase() === 'or') continue;
    for (const word of text.matchAll(WORD)) terms.add(word[0].toLowerCase());
  }
  return [...terms];
}

/** Splits text into plain and marked runs: whole words that are one of the terms. */
export function highlightSegments(text, terms) {
  const source = String(text ?? '');
  const wanted = new Set(terms.map((term) => term.toLowerCase()));
  const segments = [];
  let last = 0;
  if (wanted.size > 0) {
    for (const match of source.matchAll(WORD)) {
      if (!wanted.has(match[0].toLowerCase())) continue;
      if (match.index > last) segments.push({ text: source.slice(last, match.index), mark: false });
      segments.push({ text: match[0], mark: true });
      last = match.index + match[0].length;
    }
  }
  if (last < source.length) segments.push({ text: source.slice(last), mark: false });
  return segments;
}

/**
 * Splits text by the server's excerpt_matches (#318): {start, length} in UTF-16 code units,
 * relative to the excerpt as sent, which is how JavaScript indexes strings. Null when the
 * ranges aren't usable (missing, out of order, overlapping, outside the text), so the caller
 * can fall back to matching words here.
 */
export function rangeSegments(text, ranges) {
  const source = String(text ?? '');
  if (!Array.isArray(ranges) || ranges.length === 0) return null;
  const segments = [];
  let last = 0;
  for (const range of ranges) {
    const start = range?.start;
    const length = range?.length;
    if (!Number.isInteger(start) || !Number.isInteger(length) || length <= 0) return null;
    if (start < last || start + length > source.length) return null;
    if (start > last) segments.push({ text: source.slice(last, start), mark: false });
    segments.push({ text: source.slice(start, start + length), mark: true });
    last = start + length;
  }
  if (last < source.length) segments.push({ text: source.slice(last), mark: false });
  return segments;
}

/**
 * Fills the element with the text, its matches in <mark>: the server's ranges when it sent
 * usable ones, else whole words that are one of the terms. Text nodes only.
 */
export function renderHighlighted(element, text, terms, ranges = null) {
  element.replaceChildren();
  for (const segment of rangeSegments(text, ranges) ?? highlightSegments(text, terms)) {
    if (segment.mark) {
      const mark = document.createElement('mark');
      mark.textContent = segment.text;
      element.append(mark);
    } else {
      element.append(document.createTextNode(segment.text));
    }
  }
}
