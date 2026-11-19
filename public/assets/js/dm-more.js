// The Direct conversation header keeps one row at every width: Report and
// Block open from "More actions" (Lantern Next L6, #393; phones had this
// since #257). It is a menu button in the APG sense: the button says it has
// a menu and whether it is open; the items are menu items, one Tab stop;
// the arrow keys, Home and End move between them; Escape closes and returns
// to the button; Tab or a click elsewhere closes; choosing an item closes
// and does it. Focus goes back to the button first, so a dialog the item
// opens returns there when it closes.

const toggle = document.getElementById('dm-more-toggle');
const actions = document.getElementById('dm-actions');

if (toggle && actions) {
  const items = () => [...actions.querySelectorAll('[role="menuitem"]')].filter((item) => !item.classList.contains('hidden'));
  const isOpen = () => actions.classList.contains('open');
  const setOpen = (open, { restoreFocus = false, focus = 'first' } = {}) => {
    actions.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', String(open));
    if (open) (focus === 'last' ? items().at(-1) : items()[0])?.focus();
    else if (restoreFocus) toggle.focus();
  };
  // Offered only while there's something to do (the actions show per conversation).
  const sync = () => {
    toggle.hidden = items().length === 0;
    if (toggle.hidden && isOpen()) setOpen(false);
  };
  new MutationObserver(sync).observe(actions, { subtree: true, attributes: true, attributeFilter: ['class'] });
  sync();

  toggle.addEventListener('click', () => setOpen(!isOpen(), { restoreFocus: true }));
  toggle.addEventListener('keydown', (event) => {
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
    event.preventDefault();
    setOpen(true, { focus: event.key === 'ArrowUp' ? 'last' : 'first' });
  });
  actions.addEventListener('keydown', (event) => {
    const all = items();
    const index = all.indexOf(document.activeElement);
    const go = (next) => { event.preventDefault(); all[(next + all.length) % all.length]?.focus(); };
    if (event.key === 'ArrowDown') go(index + 1);
    else if (event.key === 'ArrowUp') go(index - 1);
    else if (event.key === 'Home') go(0);
    else if (event.key === 'End') go(all.length - 1);
    else if (event.key === 'Tab') setOpen(false);
  });
  // Capture: the menu closes and focus is on the button before the item's own handler runs.
  actions.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest('[role="menuitem"]')) setOpen(false, { restoreFocus: true });
  }, true);
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && isOpen()) {
      event.preventDefault();
      setOpen(false, { restoreFocus: true });
    }
  });
  document.addEventListener('click', (event) => {
    if (!isOpen() || !(event.target instanceof Node)) return;
    if (!actions.contains(event.target) && !toggle.contains(event.target)) setOpen(false);
  });
}
