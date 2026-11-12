// The list pane and the conversation (S2c, #168). One module for the Rooms
// page and the Direct page; each marks its frame with data-panes and names
// its parts:
//
//   <div data-panes data-list="sidebar" data-toggle="drawer-toggle"
//        data-close="drawer-close" data-backdrop="drawer-backdrop"
//        data-title="room-title" data-list-name="Rooms" data-deep-link="room_id">
//
// data-deep-link lists the query keys (comma-separated) that point at one
// conversation.
//
// Desktop (64rem and wider): the list sits beside the conversation; nothing
// to manage. Tablet (48rem to 64rem): the list opens over the conversation
// from the toggle in the conversation header, like a dialog: Escape, its
// close button or the backdrop close it and focus returns to the toggle.
// Phone (below 48rem): a stack. The list is the first screen; choosing a
// conversation pushes it (focus to its title, the bottom bar steps aside),
// and Back, or the browser's back, returns to the list with focus on the
// item that opened it.
//
// Pages announce a choice with chitchat:room-chosen or
// chitchat:conversation-chosen, detail { opener, user }: opener is the list
// item, and user is false for choices the page made itself (a deep link),
// which open the conversation without moving focus.

const tablet = window.matchMedia('(min-width: 48rem) and (max-width: 63.99rem)');
const phone = window.matchMedia('(max-width: 47.99rem)');
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';
// The open conversation's row: aria-current="page" on room links (S4a), "true" on DM rows until S4b.
const CURRENT = '[aria-current]:not([aria-current="false"])';

window.addEventListener('DOMContentLoaded', () => {
  const frame = document.querySelector('[data-panes]');
  if (!frame) return;
  const part = (name) => document.getElementById(frame.dataset[name] ?? '');
  const list = part('list');
  const toggle = part('toggle');
  const close = part('close');
  const backdrop = part('backdrop');
  const title = part('title');
  if (!list || !toggle || !title) return;
  const listName = frame.dataset.listName ?? 'list';

  let opener = null;
  let pushed = false;

  // ---- Tablet overlay ----

  function isOverlayOpen() {
    return frame.classList.contains('list-open');
  }

  // While the list is open over the conversation, everything else is inert:
  // the conversation, the strips and the rail can't be reached under the backdrop.
  const navigation = document.getElementById('app-nav');
  function setInert(on) {
    for (const node of [...frame.children, navigation]) {
      if (!node || node === list || node === backdrop) continue;
      node.inert = on;
    }
  }

  function setOverlay(open, { restoreFocus = false } = {}) {
    frame.classList.toggle('list-open', open);
    setInert(open);
    if (tablet.matches) toggle.setAttribute('aria-expanded', String(open));
    if (open) {
      (list.querySelector(CURRENT) ?? list.querySelector(FOCUSABLE))?.focus();
    } else if (restoreFocus) {
      toggle.focus();
    }
  }

  // ---- Phone stack ----

  function showConversation({ focus }) {
    if (frame.dataset.stack !== 'conversation') {
      frame.dataset.stack = 'conversation';
      // One history entry per push, so the browser's back returns to the list.
      if (!pushed) {
        window.history.pushState({ ...window.history.state, panes: 'conversation' }, '');
        pushed = true;
      }
    }
    if (focus) title.focus();
  }

  function showList({ restoreFocus }) {
    frame.dataset.stack = 'list';
    pushed = false;
    if (!restoreFocus) return;
    const target = opener?.isConnected ? opener : (list.querySelector(CURRENT) ?? list.querySelector(FOCUSABLE));
    target?.focus();
  }

  // ---- Modes ----

  function applyMode() {
    if (phone.matches) {
      frame.classList.remove('list-open');
      setInert(false);
      toggle.removeAttribute('aria-expanded');
      toggle.setAttribute('aria-label', `Back to ${listName.toLowerCase()}`);
      frame.dataset.stack ??= 'list';
    } else {
      toggle.setAttribute('aria-label', listName);
      toggle.setAttribute('aria-expanded', String(isOverlayOpen()));
      if (!tablet.matches) {
        frame.classList.remove('list-open');
        setInert(false);
      }
    }
  }

  // A deep link (a search result, an activity item, a profile's "Message")
  // starts on the conversation it points at.
  const query = new URLSearchParams(window.location.search);
  const deepLinked = (frame.dataset.deepLink ?? '').split(',').some((key) => key && query.has(key));
  frame.dataset.stack = deepLinked ? 'conversation' : 'list';
  applyMode();
  tablet.addEventListener('change', applyMode);
  phone.addEventListener('change', () => {
    if (!phone.matches) {
      applyMode();
      return;
    }
    // Narrowing a wider window keeps you in the conversation you were reading,
    // unless the list was open over it (a rotated tablet) or nothing is open.
    const wasListOpen = isOverlayOpen();
    const current = list.querySelector(CURRENT);
    frame.dataset.stack = current && !wasListOpen ? 'conversation' : 'list';
    pushed = false;
    applyMode();
    if (wasListOpen) (current ?? list.querySelector(FOCUSABLE))?.focus();
  });

  toggle.addEventListener('click', () => {
    if (phone.matches) {
      if (pushed) window.history.back();
      else showList({ restoreFocus: true });
      return;
    }
    setOverlay(!isOverlayOpen(), { restoreFocus: false });
  });
  close?.addEventListener('click', () => setOverlay(false, { restoreFocus: true }));
  backdrop?.addEventListener('click', () => {
    if (isOverlayOpen()) setOverlay(false, { restoreFocus: true });
  });
  document.addEventListener('keydown', (event) => {
    // A dialog opened from the list (Create room) handles its own Escape first.
    if (event.defaultPrevented || document.querySelector('dialog[open]')) return;
    if (event.key === 'Escape' && isOverlayOpen() && tablet.matches) {
      event.preventDefault();
      setOverlay(false, { restoreFocus: true });
    }
  });
  window.addEventListener('popstate', () => {
    if (phone.matches && frame.dataset.stack === 'conversation') showList({ restoreFocus: true });
  });

  for (const type of ['chitchat:room-chosen', 'chitchat:conversation-chosen']) {
    window.addEventListener(type, (event) => {
      const user = event.detail?.user !== false;
      if (event.detail?.opener) opener = event.detail.opener;
      if (isOverlayOpen()) setOverlay(false);
      if (phone.matches) showConversation({ focus: user });
      else if (user && tablet.matches) title.focus();
    });
  }
});
