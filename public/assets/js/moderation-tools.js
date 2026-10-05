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

const PRESETS = {
  ban: [['1 hour', 60], ['1 day', 1440], ['7 days', 10080], ['30 days', 43200]],
  mute: [['10 minutes', 10], ['1 hour', 60], ['1 day', 1440], ['7 days', 10080]],
};
const UNITS = [['minutes', 1], ['hours', 60], ['days', 1440]];
let dialogCount = 0;

/**
 * "for 1 hour", "for 10 days", or "until further notice": the wording the
 * server puts into room notices.
 */
export function spanText(minutes) {
  if (minutes === null) return 'until further notice';
  const [count, unit] = minutes >= 2880 || minutes % 1440 === 0 ? [Math.round(minutes / 1440), 'day']
    : minutes >= 120 || minutes % 60 === 0 ? [Math.round(minutes / 60), 'hour']
      : [minutes, 'minute'];
  return `for ${count} ${unit}${count === 1 ? '' : 's'}`;
}

/**
 * The ban and mute dialog: a preset or custom length, a reason, and
 * optionally "Let the room know". Resolves to the choice, or null.
 *
 * @param {{
 *   kind: 'ban' | 'mute',
 *   title: string,
 *   confirmLabel: string,
 *   reasonHint: string,
 *   effect: (until: string) => string,
 *   announce: null | ((span: string) => string),
 *   formatUntil: (date: Date) => string,
 * }} options
 * @returns {Promise<{ expiresAt: string | null, reason: string, announce: boolean } | null>}
 */
export function chooseRestriction(options) {
  const id = `restriction-${(dialogCount += 1)}`;
  const { dialog, form } = dialogShell(id, options.title);

  const lengths = document.createElement('fieldset');
  lengths.className = 'duration-choices';
  const legend = document.createElement('legend');
  legend.textContent = 'For';
  lengths.append(legend);
  const choices = [...PRESETS[options.kind], ['Custom', 'custom'], ['Until lifted', 'none']];
  choices.forEach(([label, value], index) => {
    const option = document.createElement('label');
    option.className = 'duration-chip';
    const radio = document.createElement('input');
    radio.type = 'radio';
    radio.name = `${id}-length`;
    radio.value = String(value);
    radio.checked = index === 1;
    option.append(radio, label);
    lengths.append(option);
  });

  const custom = document.createElement('div');
  custom.className = 'duration-custom hidden';
  const amount = document.createElement('input');
  amount.type = 'number';
  amount.min = '1';
  amount.max = '999';
  amount.value = '2';
  amount.setAttribute('aria-label', 'Length');
  const unit = document.createElement('select');
  unit.setAttribute('aria-label', 'Unit');
  for (const [label, factor] of UNITS) unit.append(new Option(label, String(factor)));
  unit.value = '1440';
  custom.append(amount, unit);

  const reasonLabel = document.createElement('label');
  reasonLabel.append('Reason ');
  const hint = document.createElement('span');
  hint.className = 'optional-label';
  hint.textContent = options.reasonHint;
  const reason = document.createElement('input');
  reason.type = 'text';
  reason.maxLength = 500;
  reason.autocomplete = 'off';
  reasonLabel.append(hint, reason);

  const extras = [];
  let announceBox = null;
  let announcePreview = null;
  if (options.announce) {
    const announceLabel = document.createElement('label');
    announceLabel.className = 'check-row';
    announceBox = document.createElement('input');
    announceBox.type = 'checkbox';
    announcePreview = document.createElement('span');
    announceLabel.append(announceBox, announcePreview);
    extras.push(announceLabel);
  }
  const effect = document.createElement('p');
  effect.className = 'optional-label';
  effect.setAttribute('aria-live', 'polite');

  form.append(lengths, custom, reasonLabel, ...extras, effect, actionRow(options.confirmLabel));
  dialog.append(form);
  document.body.append(dialog);

  const chosen = () => form.querySelector(`input[name="${id}-length"]:checked`)?.value ?? 'none';
  // Minutes from now, or null for "until lifted".
  const minutes = () => {
    const value = chosen();
    if (value === 'none') return null;
    if (value === 'custom') {
      return Math.max(1, Math.min(999, Math.round(Number(amount.value) || 1))) * Number(unit.value);
    }
    return Number(value);
  };
  const update = () => {
    custom.classList.toggle('hidden', chosen() !== 'custom');
    const length = minutes();
    const until = length === null ? null : new Date(Date.now() + length * 60_000);
    effect.textContent = options.effect(until === null ? 'until someone lifts it' : `until ${options.formatUntil(until)}`);
    if (announcePreview) {
      announcePreview.textContent = `Let the room know: “${options.announce(spanText(length))}” Without the reason or your name.`;
    }
  };
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  update();
  dialog.showModal();
  form.querySelector(`input[name="${id}-length"]:checked`)?.focus();

  return settle(dialog, form, () => {
    const length = minutes();
    return {
      expiresAt: length === null ? null : new Date(Date.now() + length * 60_000).toISOString(),
      reason: reason.value.trim(),
      announce: Boolean(announceBox?.checked),
    };
  });
}

const GUEST_BLOCKS = [['1 hour', 3600], ['1 day', 86400], ['1 week', 604800]];

/**
 * "Block guests from this connection": for how long, and why. The
 * moderator never sees the address itself. Resolves to the choice, or null.
 *
 * @param {{ name: string }} guest
 * @returns {Promise<{ seconds: number, reason: string } | null>}
 */
export function chooseGuestBlock({ name }) {
  const id = `guest-block-${(dialogCount += 1)}`;
  const { dialog, form } = dialogShell(id, 'Block guests from this connection?');

  const lengths = document.createElement('fieldset');
  lengths.className = 'duration-choices';
  const legend = document.createElement('legend');
  legend.textContent = 'For';
  lengths.append(legend);
  GUEST_BLOCKS.forEach(([label, seconds], index) => {
    const option = document.createElement('label');
    option.className = 'duration-chip';
    const radio = document.createElement('input');
    radio.type = 'radio';
    radio.name = `${id}-length`;
    radio.value = String(seconds);
    radio.checked = index === 1;
    option.append(radio, label);
    lengths.append(option);
  });

  const reasonLabel = document.createElement('label');
  reasonLabel.append('Reason ');
  const hint = document.createElement('span');
  hint.className = 'optional-label';
  hint.textContent = 'optional, kept in the audit log';
  const reason = document.createElement('input');
  reason.type = 'text';
  reason.maxLength = 500;
  reason.autocomplete = 'off';
  reasonLabel.append(hint, reason);

  const effect = document.createElement('p');
  effect.className = 'optional-label';
  effect.textContent = `${name} and every other guest from the same connection leave at once, and no new guest can start from it. Members there can still sign in and register.`;

  form.append(lengths, reasonLabel, effect, actionRow('Block guests'));
  dialog.append(form);
  document.body.append(dialog);
  dialog.showModal();
  form.querySelector(`input[name="${id}-length"]:checked`)?.focus();

  return settle(dialog, form, () => ({
    seconds: Number(form.querySelector(`input[name="${id}-length"]:checked`)?.value ?? 86400),
    reason: reason.value.trim(),
  }));
}

/**
 * A plain confirmation, optionally with "Let the room know".
 *
 * @param {{ title: string, text: string, confirmLabel: string, announce: string | null }} options
 * @returns {Promise<{ announce: boolean } | null>}
 */
export function confirmAction({ title, text, confirmLabel, announce }) {
  const id = `confirm-${(dialogCount += 1)}`;
  const { dialog, form } = dialogShell(id, title);
  const body = document.createElement('p');
  body.textContent = text;
  form.append(body);
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

function dialogShell(id, title) {
  const dialog = document.createElement('dialog');
  dialog.className = 'room-dialog moderation-dialog';
  dialog.setAttribute('aria-labelledby', `${id}-title`);
  const form = document.createElement('form');
  form.className = 'form-stack';
  const heading = document.createElement('h2');
  heading.id = `${id}-title`;
  heading.textContent = title;
  form.append(heading);
  return { dialog, form };
}

function actionRow(confirmLabel) {
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
function settle(dialog, form, result) {
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
