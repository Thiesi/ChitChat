const TOKEN_PATTERN = /(?:^|[^A-Za-z0-9_.@-])@([A-Za-z0-9_.-]{0,32})$/u;
const DEBOUNCE_MS = 150;

/**
 * Attaches @mention autocomplete to a composer textarea. Purely a typing
 * convenience: the suggestions come from `fetchSuggestions`, but whether a
 * suggestion (or any manually typed @username) actually becomes a real,
 * notifying mention is decided independently by the server at send time.
 *
 * Returns a controller so the composer's own Enter-submits keydown handler
 * can defer to the dropdown (via isOpen()) instead of the two handlers
 * racing to decide what Enter does on the same textarea.
 *
 * @param {HTMLTextAreaElement} textarea
 * @param {(prefix: string) => Promise<Array<{ id: number, username: string }>>} fetchSuggestions
 * @returns {{ isOpen: () => boolean }}
 */
export function attachMentionAutocomplete(textarea, fetchSuggestions) {
  const listboxId = `${textarea.id}-mentions`;
  const list = document.createElement('ul');
  list.id = listboxId;
  list.className = 'mention-autocomplete hidden';
  list.setAttribute('role', 'listbox');
  list.setAttribute('aria-label', 'Mention suggestions');
  document.body.append(list);

  textarea.setAttribute('aria-autocomplete', 'list');
  textarea.setAttribute('aria-controls', listboxId);
  textarea.setAttribute('aria-expanded', 'false');

  let match = null;
  let suggestions = [];
  let activeIndex = -1;
  let sequence = 0;

  textarea.addEventListener('input', () => queueUpdate());
  textarea.addEventListener('click', () => queueUpdate());
  textarea.addEventListener('keyup', (event) => {
    if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) queueUpdate();
  });
  textarea.addEventListener('blur', () => window.setTimeout(close, 150));
  textarea.addEventListener('keydown', handleKeydown);

  function queueUpdate() {
    const requestId = ++sequence;
    const found = detectToken(textarea.value, textarea.selectionStart ?? 0);
    if (!found) {
      close();
      return;
    }
    match = found;
    window.setTimeout(async () => {
      if (requestId !== sequence) return;
      try {
        const results = await fetchSuggestions(found.prefix);
        if (requestId !== sequence || !match) return;
        show(results);
      } catch {
        if (requestId === sequence) close();
      }
    }, DEBOUNCE_MS);
  }

  function show(results) {
    suggestions = results.slice(0, 8);
    if (suggestions.length === 0) {
      close();
      return;
    }
    activeIndex = 0;
    list.replaceChildren();
    suggestions.forEach((user, index) => {
      const option = document.createElement('li');
      option.id = `${listboxId}-${index}`;
      option.className = 'mention-autocomplete-option';
      option.setAttribute('role', 'option');
      option.textContent = `@${user.username}`;
      option.addEventListener('mousedown', (event) => {
        event.preventDefault();
        select(index);
      });
      list.append(option);
    });
    position();
    list.classList.remove('hidden');
    textarea.setAttribute('aria-expanded', 'true');
    updateActiveDescendant();
  }

  function position() {
    const rect = textarea.getBoundingClientRect();
    list.style.left = `${rect.left}px`;
    list.style.width = `${rect.width}px`;
    list.style.bottom = `${window.innerHeight - rect.top}px`;
  }

  function updateActiveDescendant() {
    [...list.children].forEach((child, index) => {
      child.setAttribute('aria-selected', String(index === activeIndex));
      child.classList.toggle('active', index === activeIndex);
    });
    if (activeIndex >= 0) {
      textarea.setAttribute('aria-activedescendant', `${listboxId}-${activeIndex}`);
    } else {
      textarea.removeAttribute('aria-activedescendant');
    }
  }

  function close() {
    match = null;
    suggestions = [];
    activeIndex = -1;
    list.classList.add('hidden');
    list.replaceChildren();
    textarea.setAttribute('aria-expanded', 'false');
    textarea.removeAttribute('aria-activedescendant');
  }

  function select(index) {
    const user = suggestions[index];
    if (!user || !match) return;
    const before = textarea.value.slice(0, match.start);
    const after = textarea.value.slice(match.start + 1 + match.prefix.length);
    const insertion = `@${user.username} `;
    textarea.value = `${before}${insertion}${after}`;
    const caret = before.length + insertion.length;
    textarea.setSelectionRange(caret, caret);
    textarea.focus();
    close();
  }

  function handleKeydown(event) {
    if (list.classList.contains('hidden')) return;
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      activeIndex = (activeIndex + 1) % suggestions.length;
      updateActiveDescendant();
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      activeIndex = (activeIndex - 1 + suggestions.length) % suggestions.length;
      updateActiveDescendant();
    } else if (event.key === 'Enter' || event.key === 'Tab') {
      event.preventDefault();
      select(activeIndex);
    } else if (event.key === 'Escape') {
      event.preventDefault();
      close();
    }
  }

  return { isOpen: () => !list.classList.contains('hidden') };
}

function detectToken(text, caretPos) {
  const match = TOKEN_PATTERN.exec(text.slice(0, caretPos));
  if (!match) return null;
  return { start: caretPos - match[1].length - 1, prefix: match[1] };
}
