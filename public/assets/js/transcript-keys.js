// Keyboard and focus model for the shared transcript (S1b, #166 §4).
// The list is one tab stop: exactly one message is in the tab order (the
// "current" message), arrows move between messages, Enter or the context
// menu key opens the message's actions, Tab reaches the current message's own
// controls only, and Esc goes to the composer. Pattern: roving tabindex in a
// composite (APG "managing focus"), with an APG menu for the actions.
// No single-letter shortcuts: they would collide with screen-reader keys.

import { announce } from './announcer.js';

const MESSAGE = 'article[data-message-id], article[data-ping-id]';
const INNER = 'button, a[href], input, select, textarea, summary, [tabindex]';
const PAGE = 10;

let helpCount = 0;

/**
 * @param {HTMLElement} list the transcript <ol>
 * @param {{ composer: HTMLElement, loadOlder: () => Promise<void>, canLoadOlder: () => boolean,
 *           selfName: () => string|null }} options
 */
export function attachTranscriptKeys(list, options) {
  let currentKey = null;
  let lastIndex = null;
  let menu = null;
  let syncing = false;
  // Incremental sync (review item 6): only messages that changed since the
  // last sync, plus the old and new current one, are re-labelled. A full pass
  // runs once at the start and after reset().
  let previous = null;
  let lastFocused = null;
  let fullPending = true;
  const dirty = new Set();

  const help = document.createElement('p');
  help.id = `transcript-help-${helpCount += 1}`;
  help.hidden = true;
  help.textContent = 'Use the arrow keys to move between messages. Enter opens actions. Escape goes to the message box.';
  list.after(help);
  list.setAttribute('aria-describedby', help.id);
  // Firefox makes a scrolling container a Tab stop of its own even when it
  // holds focusable items; the current message is the transcript's only one.
  if (!list.hasAttribute('tabindex')) list.setAttribute('tabindex', '-1');

  const messages = () => [...list.querySelectorAll(MESSAGE)];
  const keyOf = (article) => (article.dataset.messageId ? `m:${article.dataset.messageId}` : `p:${article.dataset.pingId}`);
  const find = (key) => messages().find((article) => keyOf(article) === key) ?? null;
  const current = () => {
    if (!currentKey) return null;
    if (previous?.isConnected && list.contains(previous) && keyOf(previous) === currentKey) return previous;
    return find(currentKey);
  };

  function markDirty(node, deep) {
    if (!(node instanceof Element) || node === list) return;
    const own = node.closest(MESSAGE);
    if (own) dirty.add(own);
    if (deep) for (const inner of node.querySelectorAll(MESSAGE)) dirty.add(inner);
  }

  function collect(records) {
    for (const record of records) {
      markDirty(record.target, false);
      for (const added of record.addedNodes) markDirty(added, true);
    }
  }

  /** Closes a reaction picker left open in a message (review item 5). */
  function closePickers(article) {
    for (const picker of article.querySelectorAll('.reaction-picker:not([hidden])')) {
      picker.hidden = true;
      picker.parentElement?.querySelector('.reaction-add-button')?.setAttribute('aria-expanded', 'false');
    }
  }

  function initial(all) {
    const firstUnread = list.querySelector('li:has(> .new-messages-divider) ~ li article[data-message-id]');
    return firstUnread ?? all.at(-1);
  }

  function innerControls(article) {
    return [...article.querySelectorAll(INNER)].filter((node) => node !== article && !node.closest('[hidden]'));
  }

  function setInner(article, enabled) {
    for (const node of innerControls(article)) {
      if (enabled) {
        if (node.dataset.rovingTabindex !== undefined) {
          if (node.dataset.rovingTabindex === '') node.removeAttribute('tabindex');
          else node.setAttribute('tabindex', node.dataset.rovingTabindex);
          delete node.dataset.rovingTabindex;
        }
      } else if (node.dataset.rovingTabindex === undefined) {
        node.dataset.rovingTabindex = node.getAttribute('tabindex') ?? '';
        node.setAttribute('tabindex', '-1');
      }
    }
  }

  // What a focused message says, in the §3 order (who, reply, text, time,
  // extras), independent of the visual layout.
  function label(article) {
    const key = keyOf(article).replace(':', '-');
    const parts = [];
    const own = (selector) => article.querySelector(selector);
    const ref = (node, suffix) => {
      if (!(node instanceof HTMLElement)) return;
      node.id ||= `${list.id}-${key}-${suffix}`;
      parts.push(node.id);
    };
    const hiddenSpan = (suffix, text) => {
      let span = article.querySelector(`:scope > .transcript-sr-${suffix}`);
      if (!text) { span?.remove(); return; }
      if (!span) {
        span = document.createElement('span');
        span.className = `transcript-sr-${suffix}`;
        span.hidden = true;
        span.id = `${list.id}-${key}-${suffix}`;
        article.append(span);
      }
      if (span.textContent !== text) span.textContent = text;
      parts.push(span.id);
    };

    // The bubble shows only the time (the day heading carries the date), so
    // the name reads the full moment in the account's format from the time's
    // title, plus "read" where a DM shows it.
    const stamp = (node) => {
      if (!(node instanceof HTMLElement)) return;
      const full = node.getAttribute('title')?.trim();
      if (!full) { ref(node, 'time'); return; }
      const read = node.classList.contains('dm-message-meta') && /·\s*read$/u.test(node.textContent.trim()) ? ', read' : '';
      hiddenSpan('stamp', `${full}${read}`);
    };

    if (article.classList.contains('ignored-message') || article.classList.contains('room-notice')) {
      ref(article.firstElementChild, 'text');
    } else if (article.dataset.pingId) {
      ref(own('.message-body'), 'body');
      stamp(own('.message-time'));
    } else {
      const author = article.dataset.author || own('.message-author')?.textContent?.trim() || 'Someone';
      const self = options.selfName();
      hiddenSpan('who', `${self && author === self ? 'You' : author}.`);
      const preview = own('.reply-preview');
      if (preview?.classList.contains('reply-preview-unavailable')) ref(preview, 'reply');
      else if (preview) {
        const to = preview.querySelector('.reply-preview-author')?.textContent?.trim() || 'someone';
        const excerpt = preview.querySelector('.reply-preview-excerpt')?.textContent?.trim() ?? '';
        hiddenSpan('reply', `Replying to ${to}: ${excerpt}`);
      }
      ref(own('.message-body, .dm-message-body'), 'body');
      stamp(own('.message-time, .dm-message-meta'));
      const extras = [];
      if (own('.message-edited-indicator')) extras.push('Edited.');
      const file = own('.attachment-card .attachment-download');
      if (file?.textContent?.trim()) extras.push(`File: ${file.textContent.trim()}.`);
      const reactions = [...article.querySelectorAll('.reaction-button')].map((button) => {
        const [emojiPart, countPart] = (button.getAttribute('aria-label') ?? '').split(':')[0].split(', ');
        const mine = button.getAttribute('aria-pressed') === 'true' ? ', including you' : '';
        return `${emojiPart.replace(' reaction', '')} ${countPart ?? ''}${mine}`.trim();
      });
      if (reactions.length) extras.push(`Reactions: ${reactions.join('; ')}.`);
      hiddenSpan('extra', extras.join(' '));
    }
    const value = parts.join(' ');
    if (article.getAttribute('aria-labelledby') !== value) article.setAttribute('aria-labelledby', value);
  }

  /** Re-applies the single tab stop after any render or decoration. */
  function sync() {
    if (syncing) return;
    syncing = true;
    try {
      collect(observer.takeRecords());
      const all = messages();
      if (all.length === 0) {
        currentKey = null;
        lastIndex = null;
        previous = null;
        dirty.clear();
        return;
      }
      // Focus is restored only when the node that had it was removed, never
      // after focus simply went to nothing (review item 1).
      const hadFocus = document.activeElement === document.body && lastIndex !== null
        && lastFocused !== null && !lastFocused.isConnected;
      let chosen = current();
      if (!chosen) {
        // The current message is gone (deleted): the next one, else the previous.
        chosen = lastIndex !== null ? all[Math.min(lastIndex, all.length - 1)] : initial(all);
      }
      const targets = fullPending
        ? all
        : new Set([...dirty, previous, chosen].filter((article) => article?.isConnected && list.contains(article)));
      for (const article of targets) {
        const isCurrent = article === chosen;
        // tabIndex reads -1 without the attribute too, so compare the attribute itself.
        const value = isCurrent ? '0' : '-1';
        if (article.getAttribute('tabindex') !== value) article.setAttribute('tabindex', value);
        setInner(article, isCurrent && article.contains(document.activeElement));
        label(article);
      }
      if (previous && previous !== chosen && previous.isConnected) closePickers(previous);
      fullPending = false;
      dirty.clear();
      previous = chosen;
      currentKey = keyOf(chosen);
      lastIndex = all.indexOf(chosen);
      if (hadFocus && list.dataset.transcriptFocused === 'true') focusArticle(chosen);
    } finally {
      // What sync itself changed (hidden name spans) needs no second pass.
      observer.takeRecords();
      syncing = false;
    }
  }

  function makeCurrent(article) {
    currentKey = keyOf(article);
    sync();
  }

  function focusArticle(article) {
    makeCurrent(article);
    article.focus({ preventScroll: true });
    article.scrollIntoView({ block: 'nearest', behavior: 'auto' });
  }

  function move(article, delta) {
    const all = messages();
    const index = all.indexOf(article);
    const target = all[Math.max(0, Math.min(all.length - 1, index + delta))];
    if (target && target !== article) focusArticle(target);
  }

  async function olderFromTop(article) {
    if (!options.canLoadOlder()) return;
    await options.loadOlder();
    // The same message stays current and focused; older history is above it.
    const again = find(keyOf(article));
    if (again) focusArticle(again);
  }

  list.addEventListener('keydown', (event) => {
    const article = event.target instanceof HTMLElement ? event.target.closest(MESSAGE) : null;
    if (!article || !list.contains(article)) return;
    const onMessage = event.target === article;

    if (event.key === 'Escape' && !onMessage) {
      event.preventDefault();
      closePickers(article);
      focusArticle(article);
      return;
    }
    if (!onMessage || event.altKey || event.ctrlKey || event.metaKey) return;

    const all = messages();
    switch (event.key) {
      case 'ArrowDown': event.preventDefault(); move(article, 1); break;
      case 'ArrowUp':
        event.preventDefault();
        if (all.indexOf(article) === 0) void olderFromTop(article);
        else move(article, -1);
        break;
      case 'PageDown': event.preventDefault(); move(article, PAGE); break;
      case 'PageUp': event.preventDefault(); move(article, -PAGE); break;
      case 'Home': event.preventDefault(); focusArticle(all[0]); break;
      case 'End': event.preventDefault(); focusArticle(all.at(-1)); break;
      case 'Enter':
      case 'ContextMenu':
        event.preventDefault();
        openActions(article);
        break;
      case 'F10':
        if (event.shiftKey) { event.preventDefault(); openActions(article); }
        break;
      case 'Escape': event.preventDefault(); options.composer.focus(); break;
      default: break;
    }
  });

  list.addEventListener('contextmenu', (event) => {
    const article = event.target instanceof HTMLElement ? event.target.closest(MESSAGE) : null;
    // The keyboard's context menu key arrives as contextmenu on the focused message.
    if (article && event.target === article) {
      event.preventDefault();
      openActions(article);
    }
  });

  list.addEventListener('click', (event) => {
    const more = event.target instanceof HTMLElement ? event.target.closest('.message-more-button') : null;
    const article = more?.closest(MESSAGE);
    if (article) openActions(article);
  });

  list.addEventListener('focusin', (event) => {
    list.dataset.transcriptFocused = 'true';
    if (event.target instanceof Element) lastFocused = event.target;
    const article = event.target instanceof HTMLElement ? event.target.closest(MESSAGE) : null;
    if (!article) return;
    if (keyOf(article) !== currentKey) makeCurrent(article);
    setInner(article, true);
  });

  list.addEventListener('focusout', (event) => {
    const next = event.relatedTarget;
    const article = current();
    if (article && !(next instanceof Node && article.contains(next))) setInner(article, false);
    if (next instanceof Node) {
      if (!list.contains(next)) list.dataset.transcriptFocused = 'false';
      return;
    }
    // No new target: either the focused node was removed (keep transcript
    // focus, so sync moves it to the next message) or focus went to nothing,
    // such as a click on non-focusable space (end it, so a later sync doesn't
    // pull focus and scroll back). Removal has happened by the next task.
    const from = event.target;
    window.setTimeout(() => {
      if (from instanceof Node && from.isConnected && !list.contains(document.activeElement)) {
        list.dataset.transcriptFocused = 'false';
      }
    }, 0);
  });

  // (5) Opening a message's reaction picker (the + button, or React… in the
  // actions menu) moves focus to its first option; choosing one returns focus
  // to the message, since the picker closes.
  list.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const article = target?.closest(MESSAGE);
    if (!article) return;
    if (target.closest('.reaction-picker-option')) {
      focusArticle(article);
      return;
    }
    const add = target.closest('.reaction-add-button');
    const picker = add?.parentElement?.querySelector('.reaction-picker');
    if (picker && !picker.hidden) picker.querySelector('button')?.focus();
  });

  // Tapping a message on touch opens the same actions, as a bottom sheet. It
  // opens on the final click (not pointerup), so that click's focus change
  // doesn't close it again; Safari's click has no pointerType, so remember it.
  let lastPointer = 'mouse';
  list.addEventListener('pointerdown', (event) => { lastPointer = event.pointerType; });
  list.addEventListener('click', (event) => {
    if (lastPointer !== 'touch') return;
    const target = event.target instanceof HTMLElement ? event.target : null;
    const article = target?.closest(MESSAGE);
    if (!article || target.closest('button, a, input, textarea, select, summary, label')) return;
    if (window.getSelection()?.toString()) return;
    makeCurrent(article);
    openActions(article, { sheet: true });
  });

  // Other modules decorate messages after rendering (edit/delete, files,
  // "edited"); their controls must not add tab stops.
  let queued = false;
  const observer = new MutationObserver((records) => {
    collect(records);
    if (queued || syncing) return;
    queued = true;
    queueMicrotask(() => { queued = false; sync(); });
  });
  observer.observe(list, { childList: true, subtree: true });

  // ---------- actions menu ----------

  function actionsFor(article) {
    const items = [];
    const add = (label, run, danger = false) => items.push({ label, run, danger });
    const click = (node) => () => node.click();
    const reply = article.querySelector('.message-reply-button');
    if (reply && !reply.disabled) add('Reply', click(reply));
    const react = article.querySelector('.reaction-add-button');
    if (react && !react.disabled) add('React…', click(react));
    const body = article.querySelector('.message-body, .dm-message-body');
    if (body?.textContent?.trim() && !article.classList.contains('deleted')) {
      add('Copy text', async () => {
        try {
          await navigator.clipboard.writeText(body.innerText.trim());
          announce('Copied.');
        } catch {
          announce('Copy didn’t work.');
        }
      });
    }
    const original = article.querySelector('button.reply-preview');
    if (original) add('Go to the original message', click(original));
    const file = article.querySelector('.attachment-card .attachment-download[href]');
    if (file) add('Download file', click(file));
    for (const button of article.querySelectorAll('.message-mutation-button')) {
      if (!button.disabled) add(button.textContent.trim(), click(button), button.classList.contains('danger'));
    }
    const author = article.querySelector('.message-header button.user-name, .message-author.user-name');
    if (author) add(`Open ${author.textContent.trim()}’s profile`, click(author));
    return items;
  }

  function closeMenu({ restore = true } = {}) {
    if (!menu) return;
    const { node, article } = menu;
    menu = null;
    node.remove();
    if (restore && article.isConnected) focusArticle(article);
  }

  function openActions(article, { sheet = false } = {}) {
    closeMenu({ restore: false });
    const items = actionsFor(article);
    if (items.length === 0) return;
    const node = document.createElement('div');
    node.className = `message-menu${sheet ? ' message-menu-sheet' : ''}`;
    node.setAttribute('role', 'menu');
    node.setAttribute('aria-label', 'Message actions');
    for (const item of items) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `message-menu-item${item.danger ? ' danger' : ''}`;
      button.setAttribute('role', 'menuitem');
      button.tabIndex = -1;
      button.textContent = item.label;
      button.addEventListener('click', () => {
        // Leave focus where the action puts it (Reply → composer, React → picker).
        closeMenu({ restore: false });
        article.focus({ preventScroll: true });
        void item.run();
      });
      node.append(button);
    }
    node.addEventListener('keydown', (event) => {
      const buttons = [...node.querySelectorAll('[role="menuitem"]')];
      const index = buttons.indexOf(document.activeElement);
      const go = (next) => { event.preventDefault(); buttons[(next + buttons.length) % buttons.length].focus(); };
      if (event.key === 'ArrowDown') go(index + 1);
      else if (event.key === 'ArrowUp') go(index - 1);
      else if (event.key === 'Home') go(0);
      else if (event.key === 'End') go(buttons.length - 1);
      else if (event.key === 'Escape' || event.key === 'Tab') { event.preventDefault(); closeMenu(); }
    });
    node.addEventListener('focusout', (event) => {
      if (!(event.relatedTarget instanceof Node && node.contains(event.relatedTarget))) closeMenu({ restore: false });
    });
    document.body.append(node);
    menu = { node, article };
    if (!sheet) {
      const rect = article.getBoundingClientRect();
      const left = Math.min(Math.max(8, rect.left + 48), window.innerWidth - node.offsetWidth - 8);
      const below = rect.top + 28;
      const top = below + node.offsetHeight <= window.innerHeight - 8 ? below : Math.max(8, window.innerHeight - node.offsetHeight - 8);
      node.style.left = `${left}px`;
      node.style.top = `${top}px`;
    }
    node.querySelector('[role="menuitem"]').focus();
  }

  return {
    sync,
    /** A new conversation starts at its first unread message, else its newest. */
    reset() { currentKey = null; lastIndex = null; previous = null; fullPending = true; dirty.clear(); closeMenu({ restore: false }); },
    focus(article) { if (article && list.contains(article)) focusArticle(article); },
    focusNewest() { const all = messages(); if (all.length) focusArticle(all.at(-1)); },
  };
}
