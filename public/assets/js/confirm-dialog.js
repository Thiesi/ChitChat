/*
 * The styled confirmation dialog every page uses instead of window.confirm,
 * and the parts the moderation dialogs are built from: a titled dialog, the
 * Cancel and confirm row, and settling to a result or null.
 */

let dialogCount = 0;

/**
 * A plain confirmation, optionally with "Let the room know". `items` lists
 * separate consequences, one per line (S6b: one save, several effects).
 *
 * @param {{ title: string, text: string, items?: string[], confirmLabel: string, announce: string | null }} options
 * @returns {Promise<{ announce: boolean } | null>}
 */
export function confirmAction({ title, text, items = [], confirmLabel, announce }) {
  const id = `confirm-${(dialogCount += 1)}`;
  const { dialog, form } = dialogShell(id, title);
  const body = document.createElement('p');
  body.textContent = text;
  // The effect, read with the title when the dialog opens (S7).
  body.id = `${id}-text`;
  dialog.setAttribute('aria-describedby', body.id);
  form.append(body);
  if (items.length > 0) {
    const list = document.createElement('ul');
    list.className = 'confirm-items';
    for (const line of items) {
      const item = document.createElement('li');
      item.textContent = line;
      list.append(item);
    }
    form.append(list);
  }
  let announceBox = null;
  if (announce) {
    const label = document.createElement('label');
    label.className = 'check-row';
    announceBox = document.createElement('input');
    announceBox.type = 'checkbox';
    label.append(announceBox, `Let the room know: “${announce}” Without your name.`);
    form.append(label);
  }
  form.append(actionRow(confirmLabel));
  dialog.append(form);
  document.body.append(dialog);
  dialog.showModal();
  form.querySelector('.secondary-button')?.focus();

  return settle(dialog, form, () => ({ announce: Boolean(announceBox?.checked) }));
}

/**
 * A form dialog with one text field (S7, in place of window.prompt): Save is the
 * primary action, the field starts filled and selected, an empty value can't be saved.
 *
 * @param {{ title: string, label: string, value: string, saveLabel: string, maxLength?: number }} options
 * @returns {Promise<string | null>} the trimmed new value, or null when cancelled or unchanged
 */
export function askText({ title, label, value, saveLabel, maxLength = 100 }) {
  const id = `ask-${(dialogCount += 1)}`;
  const { dialog, form } = dialogShell(id, title);
  const field = document.createElement('label');
  field.textContent = label;
  const input = document.createElement('input');
  input.type = 'text';
  input.value = value;
  input.maxLength = maxLength;
  input.required = true;
  input.autocomplete = 'off';
  field.append(input);
  const actions = actionRow(saveLabel);
  actions.querySelector('[type="submit"]').className = 'primary-button';
  form.append(field, actions);
  dialog.append(form);
  document.body.append(dialog);
  dialog.showModal();
  input.focus();
  input.select();

  return settle(dialog, form, () => {
    const next = input.value.trim();
    return next === '' || next === value.trim() ? null : next;
  });
}

export function dialogShell(id, title) {
  const dialog = document.createElement('dialog');
  dialog.className = 'room-dialog confirm-dialog';
  dialog.setAttribute('aria-labelledby', `${id}-title`);
  const form = document.createElement('form');
  form.className = 'form-stack';
  const heading = document.createElement('h2');
  heading.id = `${id}-title`;
  heading.textContent = title;
  form.append(heading);
  return { dialog, form };
}

export function actionRow(confirmLabel) {
  const actions = document.createElement('div');
  actions.className = 'action-row';
  const cancel = document.createElement('button');
  cancel.type = 'button';
  cancel.className = 'secondary-button';
  cancel.textContent = 'Cancel';
  const confirm = document.createElement('button');
  confirm.type = 'submit';
  confirm.className = 'danger-button';
  confirm.textContent = confirmLabel;
  actions.append(cancel, confirm);
  return actions;
}

// Resolves with `result()` on submit, null on Cancel or Escape, and removes the dialog.
export function settle(dialog, form, result) {
  return new Promise((resolve) => {
    const finish = (value) => {
      dialog.close();
      dialog.remove();
      resolve(value);
    };
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      finish(result());
    });
    form.querySelector('.secondary-button')?.addEventListener('click', () => finish(null));
    dialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      finish(null);
    });
  });
}
