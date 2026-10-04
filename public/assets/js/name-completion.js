// IRC-style name completion for a composer: type the start of a name and
// press Tab to complete it; press Tab again to cycle through other matches.
// Tab is only taken when a name actually matches, so it still moves focus
// otherwise, and Shift+Tab is never intercepted.

const WORD_BEFORE_CARET = /(^|\s)(@?)([A-Za-z0-9][A-Za-z0-9_.-]*)$/u;

/**
 * @param {HTMLTextAreaElement} textarea
 * @param {() => string[]} candidates usernames, best first (recent speakers first)
 */
export function attachNameCompletion(textarea, candidates) {
  let cycle = null;

  textarea.addEventListener('keydown', (event) => {
    if (event.key !== 'Tab' || event.shiftKey || event.ctrlKey || event.altKey || event.metaKey) return;
    if (event.defaultPrevented || event.isComposing) return;

    if (cycle && cycle.value === textarea.value && cycle.caret === textarea.selectionStart) {
      cycle.index = (cycle.index + 1) % cycle.matches.length;
      apply(cycle);
      event.preventDefault();
      return;
    }
    cycle = null;

    const caret = textarea.selectionStart ?? 0;
    if (caret !== textarea.selectionEnd) return;
    const found = WORD_BEFORE_CARET.exec(textarea.value.slice(0, caret));
    if (!found) return;

    const prefix = found[3].toLowerCase();
    const seen = new Set();
    const matches = candidates().filter((name) => {
      const key = name.toLowerCase();
      if (!key.startsWith(prefix) || seen.has(key)) return false;
      seen.add(key);
      return true;
    });
    if (matches.length === 0) return;

    const start = caret - found[2].length - found[3].length;
    cycle = {
      start,
      end: caret,
      at: found[2],
      // A name opening a plain message is addressed IRC-style ("Alex: ").
      suffix: start === 0 && found[2] === '' ? ': ' : ' ',
      matches,
      index: 0,
      value: '',
      caret: 0,
    };
    apply(cycle);
    event.preventDefault();
  });

  function apply(state) {
    const insertion = `${state.at}${state.matches[state.index]}${state.suffix}`;
    const value = `${textarea.value.slice(0, state.start)}${insertion}${textarea.value.slice(state.end)}`;
    textarea.value = value;
    const caret = state.start + insertion.length;
    textarea.setSelectionRange(caret, caret);
    state.end = caret;
    state.value = value;
    state.caret = caret;
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  }
}
