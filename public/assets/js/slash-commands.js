/*
 * Slash commands in the room composer. /me and /ping are understood by the
 * server; the rest run here on top of ordinary API calls. Typing "/" at the
 * start of the composer lists them, like "@" lists people.
 */

export const SHRUG = '¯\\_(ツ)_/¯';

/** Every command, in the order the list and /help show them. */
export const COMMANDS = [
  { name: 'me', usage: '/me action', description: 'Say what you are doing, in the third person' },
  { name: 'ping', usage: '/ping name message', description: 'Get someone’s attention; only the two of you see it' },
  { name: 'dm', usage: '/dm name message', description: 'Send someone a direct message, or open the conversation' },
  { name: 'topic', usage: '/topic text', description: 'Change this room’s info line', manage: true },
  { name: 'shrug', usage: '/shrug message', description: `Add ${SHRUG}` },
  { name: 'help', usage: '/help', description: 'Show commands and formatting' },
];

/** Formatting that messages understand, for /help. */
export const FORMATTING = [
  ['*bold*', 'bold'],
  ['_italic_', 'italic'],
  ['`code`', 'code'],
  ['```', 'a code block, on lines of their own before and after'],
  ['> quote', 'a quote, at the start of a line'],
];

/**
 * Splits "/name rest" into its parts, or returns null for text that is not
 * a command. Names are case-insensitive.
 *
 * @returns {{ name: string, args: string } | null}
 */
export function parseSlashCommand(text) {
  const match = /^\/([A-Za-z]+)(?:\s+([\s\S]*))?$/u.exec(String(text).trim());
  return match ? { name: match[1].toLowerCase(), args: (match[2] ?? '').trim() } : null;
}

/** Splits "name rest of the message" into the name and the rest. */
export function splitTarget(args) {
  const match = /^@?(\S+)(?:\s+([\s\S]*))?$/u.exec(args);
  return match ? { name: match[1], message: (match[2] ?? '').trim() } : null;
}

/**
 * Lists matching commands while the composer holds only "/" and the start
 * of a command name. Returns a controller so the composer's Enter handler
 * can defer to the list, as with @mention suggestions.
 *
 * @param {HTMLTextAreaElement} textarea
 * @param {() => typeof COMMANDS} available the commands this person may use here
 * @returns {{ isOpen: () => boolean }}
 */
export function attachCommandSuggestions(textarea, available) {
  const listboxId = `${textarea.id}-commands`;
  const list = document.createElement('ul');
  list.id = listboxId;
  list.className = 'mention-autocomplete command-suggestions hidden';
  list.setAttribute('role', 'listbox');
  list.setAttribute('aria-label', 'Commands');
  document.body.append(list);

  let matches = [];
  let activeIndex = -1;

  textarea.addEventListener('input', update);
  textarea.addEventListener('click', update);
  textarea.addEventListener('blur', () => window.setTimeout(close, 150));
  textarea.addEventListener('keydown', handleKeydown);

  function update() {
    const caret = textarea.selectionStart ?? 0;
    const match = /^\/([A-Za-z]*)$/u.exec(textarea.value.slice(0, caret));
    if (!match || textarea.value.length !== caret) {
      close();
      return;
    }
    const prefix = match[1].toLowerCase();
    matches = available().filter((command) => command.name.startsWith(prefix));
    if (matches.length === 0) {
      close();
      return;
    }
    activeIndex = 0;
    list.replaceChildren(...matches.map((command, index) => {
      const option = document.createElement('li');
      option.id = `${listboxId}-${index}`;
      option.className = 'mention-autocomplete-option command-option';
      option.setAttribute('role', 'option');
      const usage = document.createElement('code');
      usage.textContent = command.usage;
      const description = document.createElement('span');
      description.textContent = command.description;
      option.append(usage, description);
      option.addEventListener('mousedown', (event) => {
        event.preventDefault();
        select(index);
      });
      return option;
    }));
    const rect = textarea.getBoundingClientRect();
    list.style.left = `${rect.left}px`;
    list.style.width = `${rect.width}px`;
    list.style.bottom = `${window.innerHeight - rect.top}px`;
    list.classList.remove('hidden');
    textarea.setAttribute('aria-controls', listboxId);
    markActive();
  }

  function markActive() {
    [...list.children].forEach((child, index) => {
      child.setAttribute('aria-selected', String(index === activeIndex));
      child.classList.toggle('active', index === activeIndex);
    });
    textarea.setAttribute('aria-activedescendant', `${listboxId}-${activeIndex}`);
  }

  function close() {
    if (list.classList.contains('hidden')) return;
    matches = [];
    activeIndex = -1;
    list.classList.add('hidden');
    list.replaceChildren();
    textarea.removeAttribute('aria-activedescendant');
  }

  function select(index) {
    const command = matches[index];
    if (!command) return;
    textarea.value = `/${command.name} `;
    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
    textarea.focus();
    close();
  }

  function handleKeydown(event) {
    if (list.classList.contains('hidden')) return;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      const step = event.key === 'ArrowDown' ? 1 : -1;
      activeIndex = (activeIndex + step + matches.length) % matches.length;
      markActive();
    } else if (event.key === 'Enter' && typedInFull()) {
      // "/help" typed out in full: Enter runs it, as the composer handles it.
      close();
    } else if (event.key === 'Enter' || (event.key === 'Tab' && !event.shiftKey)) {
      // Shift+Tab always leaves the composer, so keyboard users are never trapped.
      event.preventDefault();
      event.stopImmediatePropagation();
      select(activeIndex);
    } else if (event.key === 'Escape') {
      event.preventDefault();
      close();
    }
  }

  function typedInFull() {
    const command = matches[activeIndex];
    return Boolean(command) && textarea.value.toLowerCase() === `/${command.name}`;
  }

  // The composer leaves Enter to the list only while Enter would pick from it.
  return { isOpen: () => !list.classList.contains('hidden') && !typedInFull() };
}

/** The /help dialog: the commands this person can use here, and formatting. */
export function showCommandHelp(commands) {
  let dialog = document.getElementById('command-help');
  if (!dialog) {
    dialog = document.createElement('dialog');
    dialog.id = 'command-help';
    dialog.className = 'command-help';
    dialog.setAttribute('aria-labelledby', 'command-help-title');
    document.body.append(dialog);
  }
  const title = document.createElement('h2');
  title.id = 'command-help-title';
  title.textContent = 'Commands and formatting';
  const commandList = definitionList(commands.map((command) => [command.usage, command.description]));
  const formattingTitle = document.createElement('h3');
  formattingTitle.textContent = 'Formatting';
  const formattingList = definitionList(FORMATTING);
  const note = document.createElement('p');
  note.className = 'command-help-note';
  note.textContent = 'Links starting with http:// or https:// become clickable. Enter sends; Shift+Enter adds a line.';
  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'secondary-button';
  close.textContent = 'Close';
  close.addEventListener('click', () => dialog.close());
  dialog.replaceChildren(title, commandList, formattingTitle, formattingList, note, close);
  dialog.showModal();
  close.focus();
}

function definitionList(entries) {
  const list = document.createElement('dl');
  list.className = 'command-help-list';
  for (const [term, description] of entries) {
    const dt = document.createElement('dt');
    const code = document.createElement('code');
    code.textContent = term;
    dt.append(code);
    const dd = document.createElement('dd');
    dd.textContent = description;
    list.append(dt, dd);
  }
  return list;
}
