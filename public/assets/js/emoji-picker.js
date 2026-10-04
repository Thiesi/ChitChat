import { EMOJI_CATEGORIES } from './emoji-data.js';

const RECENT_KEY = 'chitchat-recent-emoji';
const RECENT_LIMIT = 16;

/**
 * Adds an emoji picker to a composer: `button` toggles a small dialog that
 * inserts the chosen emoji at the textarea's caret. The grid has one tab stop;
 * arrow keys move between emoji by their on-screen position.
 */
export function attachEmojiPicker(button, textarea) {
  const names = new Map(EMOJI_CATEGORIES.flatMap(([, entries]) => entries.map(([emoji, name]) => [emoji, name])));
  const picker = document.createElement('div');
  picker.className = 'emoji-picker';
  picker.id = `${button.id}-picker`;
  picker.setAttribute('role', 'dialog');
  picker.setAttribute('aria-label', 'Emoji');
  picker.hidden = true;
  button.setAttribute('aria-controls', picker.id);
  button.setAttribute('aria-expanded', 'false');

  const search = document.createElement('input');
  search.type = 'search';
  search.className = 'emoji-search';
  search.placeholder = 'Search emoji';
  search.setAttribute('aria-label', 'Search emoji');
  search.autocomplete = 'off';

  const scroller = document.createElement('div');
  scroller.className = 'emoji-scroll';
  const recentSection = buildSection('Recently used', []);
  scroller.append(recentSection.section);
  for (const [category, entries] of EMOJI_CATEGORIES) {
    scroller.append(buildSection(category, entries).section);
  }
  const empty = document.createElement('p');
  empty.className = 'emoji-empty';
  empty.textContent = 'No emoji found.';
  empty.hidden = true;
  scroller.append(empty);
  picker.append(search, scroller);
  button.parentElement.append(picker);

  function buildSection(title, entries) {
    const section = document.createElement('section');
    section.className = 'emoji-section';
    const heading = document.createElement('h3');
    heading.textContent = title;
    const grid = document.createElement('div');
    grid.className = 'emoji-grid';
    grid.setAttribute('role', 'group');
    grid.setAttribute('aria-label', title);
    for (const [emoji, name, ...keywords] of entries) grid.append(optionButton(emoji, name, keywords));
    section.append(heading, grid);
    return { section, grid };
  }

  function optionButton(emoji, name, keywords) {
    const option = document.createElement('button');
    option.type = 'button';
    option.className = 'emoji-option';
    option.textContent = emoji;
    option.tabIndex = -1;
    option.setAttribute('aria-label', name);
    option.dataset.search = [name, ...keywords].join(' ').toLowerCase();
    return option;
  }

  function visibleOptions() {
    return [...picker.querySelectorAll('.emoji-option')].filter((option) => option.offsetParent !== null);
  }

  function setActive(option) {
    for (const other of picker.querySelectorAll('.emoji-option[tabindex="0"]')) other.tabIndex = -1;
    if (option) option.tabIndex = 0;
  }

  function renderRecent() {
    recentSection.grid.replaceChildren(
      ...readRecent().filter((emoji) => names.has(emoji)).map((emoji) => optionButton(emoji, names.get(emoji), [])),
    );
    recentSection.section.hidden = recentSection.grid.childElementCount === 0;
  }

  function filter() {
    const query = search.value.trim().toLowerCase();
    for (const section of picker.querySelectorAll('.emoji-section')) {
      let visible = 0;
      for (const option of section.querySelectorAll('.emoji-option')) {
        option.hidden = query !== '' && !option.dataset.search.includes(query);
        if (!option.hidden) visible += 1;
      }
      section.hidden = visible === 0 || (section === recentSection.section && query !== '');
    }
    empty.hidden = visibleOptions().length > 0;
    setActive(visibleOptions()[0] ?? null);
  }

  function open() {
    renderRecent();
    search.value = '';
    picker.hidden = false;
    button.setAttribute('aria-expanded', 'true');
    filter();
    scroller.scrollTop = 0;
    search.focus();
  }

  function close({ restoreFocus = false } = {}) {
    if (picker.hidden) return;
    picker.hidden = true;
    button.setAttribute('aria-expanded', 'false');
    if (restoreFocus) button.focus();
  }

  function insert(emoji) {
    const start = textarea.selectionStart ?? textarea.value.length;
    const end = textarea.selectionEnd ?? start;
    textarea.focus();
    textarea.setRangeText(emoji, start, end, 'end');
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    rememberRecent(emoji);
    close();
  }

  function moveFrom(option, key) {
    const options = visibleOptions();
    const index = options.indexOf(option);
    if (key === 'ArrowLeft') return options[index - 1];
    if (key === 'ArrowRight') return options[index + 1];
    if (key === 'Home') return options[0];
    if (key === 'End') return options[options.length - 1];
    // Up and down move to the nearest emoji in the row above or below.
    const here = option.getBoundingClientRect();
    const rows = options.filter((candidate) => {
      const box = candidate.getBoundingClientRect();
      return key === 'ArrowDown' ? box.top > here.bottom - 1 : box.bottom < here.top + 1;
    });
    if (rows.length === 0) return undefined;
    const rowTop = key === 'ArrowDown'
      ? Math.min(...rows.map((candidate) => candidate.getBoundingClientRect().top))
      : Math.max(...rows.map((candidate) => candidate.getBoundingClientRect().top));
    return rows
      .filter((candidate) => Math.abs(candidate.getBoundingClientRect().top - rowTop) < 2)
      .reduce((best, candidate) => (
        Math.abs(candidate.getBoundingClientRect().left - here.left) < Math.abs(best.getBoundingClientRect().left - here.left)
          ? candidate
          : best
      ));
  }

  button.addEventListener('click', () => (picker.hidden ? open() : close({ restoreFocus: true })));
  search.addEventListener('input', filter);
  search.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowDown' || event.key === 'Enter') {
      const first = visibleOptions()[0];
      if (!first) return;
      event.preventDefault();
      if (event.key === 'Enter') insert(first.textContent);
      else first.focus();
    }
  });
  picker.addEventListener('click', (event) => {
    const option = event.target.closest('.emoji-option');
    if (option) insert(option.textContent);
  });
  picker.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      close({ restoreFocus: true });
      return;
    }
    const option = event.target.closest('.emoji-option');
    if (!option || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const next = moveFrom(option, event.key);
    if (next) {
      setActive(next);
      next.focus();
    } else if (event.key === 'ArrowUp') {
      search.focus();
    }
  });
  picker.addEventListener('focusin', (event) => {
    const option = event.target.closest('.emoji-option');
    if (option) setActive(option);
  });
  document.addEventListener('pointerdown', (event) => {
    if (!picker.hidden && !picker.contains(event.target) && !button.contains(event.target)) close();
  });
}

function readRecent() {
  try {
    const stored = JSON.parse(window.localStorage.getItem(RECENT_KEY) ?? '[]');
    return Array.isArray(stored) ? stored.filter((value) => typeof value === 'string') : [];
  } catch {
    return [];
  }
}

function rememberRecent(emoji) {
  try {
    const recent = [emoji, ...readRecent().filter((value) => value !== emoji)].slice(0, RECENT_LIMIT);
    window.localStorage.setItem(RECENT_KEY, JSON.stringify(recent));
  } catch {
    // Without storage the recent row simply stays empty.
  }
}
