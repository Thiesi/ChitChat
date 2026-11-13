/*
 * Editing a message in its bubble (S7, #173), in place of window.prompt: the
 * body becomes a text box with Save and Cancel. Enter saves, Shift+Enter is a
 * new line, Escape cancels. Saving keeps the box open until the server has
 * the new text, so a failed save keeps the edit to try again. While it's open the article carries
 * data-editing, so a live update to the same message waits until it closes
 * (the caller re-applies it). Focus returns to the button that opened it.
 */

let editorCount = 0;

/**
 * `save(text)` sends it: resolve to close the editor, throw to keep it open (the caller has
 * said what went wrong). After it closes the old body is back; the caller draws the new one.
 *
 * @param {{ article: HTMLElement, body: HTMLElement, text: string, label: string, save: (text: string) => Promise<void>, opener?: HTMLElement | null, maxLength?: number }} options
 * @returns {Promise<boolean>} true when saved, false when cancelled or unchanged
 */
export function editInline({ article, body, text, label, save: send, opener = null, maxLength = 4000 }) {
  const id = `inline-edit-${(editorCount += 1)}`;
  const original = [...body.childNodes];
  article.dataset.editing = 'true';

  const form = document.createElement('form');
  form.className = 'inline-edit';
  const input = document.createElement('textarea');
  input.id = id;
  input.className = 'inline-edit-input';
  input.value = text;
  input.maxLength = maxLength;
  input.rows = 1;
  input.required = true;
  input.setAttribute('aria-label', label);
  input.setAttribute('aria-describedby', `${id}-hint`);
  const hint = document.createElement('p');
  hint.id = `${id}-hint`;
  hint.className = 'inline-edit-hint';
  hint.textContent = 'Enter saves · Shift+Enter adds a line · Esc cancels';
  const actions = document.createElement('div');
  actions.className = 'inline-edit-actions';
  const cancel = document.createElement('button');
  cancel.type = 'button';
  cancel.className = 'secondary-button';
  cancel.textContent = 'Cancel';
  const save = document.createElement('button');
  save.type = 'submit';
  save.className = 'primary-button';
  save.textContent = 'Save';
  actions.append(cancel, save);
  form.append(input, hint, actions);
  body.replaceChildren(form);

  const fit = () => {
    input.style.height = 'auto';
    input.style.height = `${input.scrollHeight}px`;
  };
  fit();
  input.focus();
  input.setSelectionRange(input.value.length, input.value.length);

  return new Promise((resolve) => {
    let saving = false;
    const finish = (saved) => {
      delete article.dataset.editing;
      if (form.isConnected) body.replaceChildren(...original);
      const target = opener?.isConnected ? opener : article;
      if (target.isConnected) target.focus({ preventScroll: true });
      resolve(saved);
    };
    const submit = async () => {
      if (saving) return;
      const next = input.value.trim();
      if (next === '' || next === text.trim()) {
        finish(false);
        return;
      }
      saving = true;
      input.readOnly = true;
      save.disabled = true;
      cancel.disabled = true;
      try {
        await send(next);
        finish(true);
      } catch {
        saving = false;
        input.readOnly = false;
        save.disabled = false;
        cancel.disabled = false;
        input.focus();
      }
    };
    input.addEventListener('input', fit);
    input.addEventListener('keydown', (event) => {
      // The transcript has its own keys (arrows, Enter, Escape): typing here isn't for it.
      event.stopPropagation();
      if (event.isComposing) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        if (!saving) finish(false);
      } else if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        void submit();
      }
    });
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      void submit();
    });
    cancel.addEventListener('click', () => finish(false));
  });
}
