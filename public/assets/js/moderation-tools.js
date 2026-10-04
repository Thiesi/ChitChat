/*
 * Moderation where it happens: the dialogs a moderator uses on a message or
 * a person, built like the other ChitChat dialogs. The server decides who
 * may do what; these only ask what to do.
 */

let deleteDialog = null;

/**
 * Asks to confirm deleting someone else's message, with an optional reason
 * for the audit log. Resolves to `{ reason }`, or null when cancelled.
 *
 * @param {{ author: string, excerpt: string, roomName: string }} message
 * @returns {Promise<{ reason: string } | null>}
 */
export function confirmModeratorDelete({ author, excerpt, roomName }) {
  const parts = ensureDeleteDialog();
  parts.quoteAuthor.textContent = author;
  parts.quoteText.textContent = excerpt === '' ? 'An attachment' : excerpt;
  parts.note.textContent = `Everyone sees “Message deleted by a moderator.” ${author} is told that a moderator removed one of their messages${roomName ? ` in #${roomName}` : ''}.`;
  parts.reason.value = '';
  parts.dialog.showModal();
  parts.cancel.focus();

  return new Promise((resolve) => {
    const finish = (result) => {
      parts.form.removeEventListener('submit', submit);
      parts.cancel.removeEventListener('click', cancel);
      parts.dialog.removeEventListener('close', cancel);
      if (parts.dialog.open) parts.dialog.close();
      resolve(result);
    };
    const submit = (event) => {
      event.preventDefault();
      finish({ reason: parts.reason.value.trim() });
    };
    const cancel = () => finish(null);
    parts.form.addEventListener('submit', submit);
    parts.cancel.addEventListener('click', cancel);
    parts.dialog.addEventListener('close', cancel);
  });
}

function ensureDeleteDialog() {
  if (deleteDialog) return deleteDialog;
  const dialog = document.createElement('dialog');
  dialog.className = 'room-dialog moderation-dialog';
  dialog.setAttribute('aria-labelledby', 'moderator-delete-title');
  const form = document.createElement('form');
  form.className = 'form-stack';

  const title = document.createElement('h2');
  title.id = 'moderator-delete-title';
  title.textContent = 'Delete this message?';

  const quote = document.createElement('blockquote');
  quote.className = 'moderation-quote';
  const quoteAuthor = document.createElement('strong');
  const quoteText = document.createElement('span');
  quote.append(quoteAuthor, ' · ', quoteText);

  const label = document.createElement('label');
  label.htmlFor = 'moderator-delete-reason';
  label.append('Reason ');
  const hint = document.createElement('span');
  hint.className = 'optional-label';
  hint.textContent = 'optional, kept in the audit log';
  label.append(hint);
  const reason = document.createElement('input');
  reason.id = 'moderator-delete-reason';
  reason.type = 'text';
  reason.maxLength = 500;
  reason.autocomplete = 'off';
  label.append(reason);

  const note = document.createElement('p');
  note.className = 'optional-label';

  const actions = document.createElement('div');
  actions.className = 'action-row';
  const cancel = document.createElement('button');
  cancel.type = 'button';
  cancel.className = 'secondary-button';
  cancel.textContent = 'Cancel';
  const confirm = document.createElement('button');
  confirm.type = 'submit';
  confirm.className = 'danger-button';
  confirm.textContent = 'Delete message';
  actions.append(cancel, confirm);

  form.append(title, quote, label, note, actions);
  dialog.append(form);
  document.body.append(dialog);
  deleteDialog = { dialog, form, quoteAuthor, quoteText, reason, note, cancel };
  return deleteDialog;
}
